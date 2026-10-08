<?php

namespace App\Http\Controllers;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\RsvpApprovalException;
use App\Http\Requests\RejectRsvpApprovalRequest;
use App\Http\Requests\SetGuestRsvpRequest;
use App\Http\Requests\StoreGuestRequest;
use App\Http\Requests\UpdateGuestRequest;
use App\Models\Event;
use App\Models\Guest;
use App\Services\CommunicationService;
use App\Services\GuestCreator;
use App\Services\HostRsvpOverrideService;
use App\Services\QrCodeService;
use App\Services\RsvpApprovalService;
use App\Services\RsvpSubmissionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestController extends Controller
{
    /**
     * The three read paths below — index, export, exportPdf — all list the same
     * guests under the same filters. One place for the WHERE chain so a new
     * filter (like checked_in) can't land in two of the three and drift.
     *
     * @param  Builder<Guest>|Relation<Guest, Event, *>  $query  Already scoped to the event, e.g. $event->guests().
     * @return Builder<Guest>|Relation<Guest, Event, *>
     */
    private function applyGuestFilters(Builder|Relation $query, Request $request): Builder|Relation
    {
        $query
            ->search($request->query('q'))
            ->forGuestGroupFilter($request->query('group'))
            ->forInvitationSentFilter($request->query('invitation_sent'))
            ->forPlusOneFilter($request->query('plus_one'))
            ->forCheckedInFilter($request->query('checked_in'));

        return match ((string) $request->query('response', 'all')) {
            'pending' => $query->whereDoesntHave('rsvp'),
            'responded' => $query->whereHas('rsvp'),
            RsvpStatus::Accepted->value => $query->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted)),
            RsvpStatus::Declined->value => $query->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined)),
            RsvpStatus::Maybe->value => $query->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Maybe)),
            // Named distinctly from 'pending' above — that already means "hasn't
            // responded at all," this means "responded Accepted, awaiting host review."
            'awaiting_approval' => $query->whereHas('rsvp', fn ($q) => $q->where('host_approval_status', RsvpApprovalStatus::Pending)),
            default => $query,
        };
    }

    public function index(Request $request, Event $event): View
    {
        $this->authorizeInvitation($event);

        $groups = $event->guestGroups()->get();

        $filter = (string) $request->query('response', 'all');

        $guestsQuery = $this->applyGuestFilters(
            $event->guests()->with(['rsvp', 'group', 'eventTable']),
            $request
        )->orderBy('name');

        $guests = $guestsQuery->paginate(30)->withQueryString();

        $tables = $event->tables()->orderBy('sort_order')->orderBy('label')->get();

        $stats = [
            'total' => $event->guests()->count(),
            'pending' => $event->guests()->whereDoesntHave('rsvp')->count(),
            'accepted' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted))->count(),
            'declined' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined))->count(),
            'awaiting_approval' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('host_approval_status', RsvpApprovalStatus::Pending))->count(),
        ];

        $whatsappSendEnabled = (bool) config('communications.whatsapp.enabled', false);

        return view('events.guests.index', compact('event', 'guests', 'filter', 'groups', 'tables', 'stats', 'whatsappSendEnabled'));
    }

    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorizeInvitation($event);

        $guestsQuery = $this->applyGuestFilters(
            $event->guests()->with(['rsvp', 'group', 'checkedInBy'])->withMax('rsvpChanges as last_rsvp_change_at', 'created_at'),
            $request
        )->orderBy('name');

        $filename = 'guests-'.str($event->name)->slug().'.csv';

        return response()->streamDownload(function () use ($guestsQuery) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Name', 'Email', 'Phone', 'Group',
                'RSVP Status', 'Attendee Count', 'Message',
                'Invitation Sent', 'Invitation Sent At',
                'Checked In At', 'Checked In By', 'Response Last Changed',
            ]);

            $guestsQuery->chunk(200, function ($chunk) use ($handle) {
                foreach ($chunk as $guest) {
                    $rsvp = $guest->rsvp;
                    fputcsv($handle, [
                        $guest->name,
                        $guest->email ?? '',
                        $guest->phone ?? '',
                        $guest->group?->name ?? '',
                        $rsvp ? $rsvp->status->value : 'pending',
                        $rsvp && $rsvp->status->countsTowardGuestLimit() ? $rsvp->attendee_count : '',
                        $rsvp?->message ?? '',
                        $guest->invitation_sent ? 'Yes' : 'No',
                        $guest->invitation_sent_at?->format('Y-m-d H:i') ?? '',
                        $guest->checked_in_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
                        // A dashboard scan resolves to the staff member's name; a
                        // door-staff-link scan has no user behind it, so it falls
                        // back to that link's snapshotted label. Blank only when
                        // nobody scanned this guest in — including rows checked in
                        // before links were recorded.
                        $guest->checkedInByLabel() ?? '',
                        $guest->last_rsvp_change_at ? Carbon::parse($guest->last_rsvp_change_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') : '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request, Event $event): Response
    {
        $this->authorizeInvitation($event);

        $filter = (string) $request->query('response', 'all');

        $guestsQuery = $this->applyGuestFilters(
            $event->guests()->with(['rsvp', 'group', 'checkedInBy']),
            $request
        )->orderBy('name');

        $guests = $guestsQuery->get();

        $filterLabels = [
            'pending' => 'Pending',
            'responded' => 'Responded',
            'accepted' => 'Accepted',
            'declined' => 'Declined',
            'maybe' => 'Maybe',
        ];
        $filterLabel = $filter !== 'all' ? ($filterLabels[$filter] ?? null) : null;

        $checkedInFilter = (string) $request->query('checked_in', '');
        $checkedInLabel = match ($checkedInFilter) {
            'yes' => 'Checked in',
            'no' => 'Not checked in',
            default => null,
        };

        $filename = 'guests-'.str($event->name)->slug().'.pdf';

        $pdf = Pdf::loadView('events.guests.export-pdf', compact('event', 'guests', 'filterLabel', 'checkedInLabel'))
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    public function create(Event $event): View
    {
        $this->authorizeInvitation($event);

        $groups = $event->guestGroups()->get();
        $tables = $event->tables()->orderBy('sort_order')->orderBy('label')->get();

        return view('events.guests.create', compact('event', 'groups', 'tables'));
    }

    public function store(StoreGuestRequest $request, Event $event, GuestCreator $guestCreator): RedirectResponse
    {
        abort_unless($event->isInvitation(), 404);

        if ($event->hasReachedGuestCapacity()) {
            return back()->withErrors([
                'name' => 'This event has reached the '.$event->guestCapacity().'-guest limit for its plan. Upgrade to add more.',
            ])->withInput();
        }

        $guest = $guestCreator->create($event, $request->validated());

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-created')
            ->with('guest_unreachable', $guest->hasNoContactDetails() ? $guest->name : null)
            ->with('guest_same_name', Guest::sameNameCount($event, $guest->name, ignoreGuestId: $guest->id) > 0 ? $guest->name : null);
    }

    public function edit(Event $event, Guest $guest): View
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);
        abort_unless($event->isInvitation(), 404);

        $groups = $event->guestGroups()->get();
        $tables = $event->tables()->orderBy('sort_order')->orderBy('label')->get();
        $changes = $guest->rsvpChanges()->with('actor')->latest('id')->limit(30)->get();

        return view('events.guests.edit', compact('event', 'guest', 'groups', 'tables', 'changes'));
    }

    public function update(UpdateGuestRequest $request, Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        abort_unless($event->isInvitation(), 404);
        $validated = $request->validated();

        $data = [
            'guest_group_id' => $validated['guest_group_id'] ?? null,
            'event_table_id' => $validated['event_table_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'plus_one_allowed' => $validated['plus_one_allowed'] ?? false,
        ];

        if (($validated['mark_invitation_sent'] ?? false) === true) {
            $data['invitation_sent'] = true;
            $data['invitation_sent_at'] = now();
        }

        if (($validated['regenerate_invitation_token'] ?? false) === true) {
            $data['invitation_token'] = Str::random(48);
        }

        $plusOneSwitchedOff = $guest->plus_one_allowed && ! $data['plus_one_allowed'];

        $guest->fill($data)->save();

        // Their confirmed plus-one stays; say so, since the checkbox suggests otherwise.
        if ($plusOneSwitchedOff && $guest->rsvp?->heldSeats() >= 2) {
            session()->flash('plus_one_kept', $guest->name);
        }

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-updated');
    }

    public function markInvitationSent(Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $guest->forceFill([
            'invitation_sent' => true,
            'invitation_sent_at' => now(),
        ])->save();

        return back()->with('status', 'guest-invitation-marked-sent');
    }

    /**
     * plans/rsvp-host-approval.md — approves a Pending RSVP, which triggers the same
     * confirmation + entry-pass send an approval-not-required Accepted RSVP already gets.
     */
    public function approveRsvp(Event $event, Guest $guest, RsvpApprovalService $rsvpApprovalService): RedirectResponse
    {
        $guest->loadMissing('event', 'rsvp');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);
        abort_if($guest->rsvp === null, 404);

        try {
            $rsvpApprovalService->approve($guest->rsvp, $event->user);
        } catch (RsvpApprovalException $e) {
            return back()->withErrors(['rsvp_approval' => $e->getMessage()]);
        }

        return back()->with('status', 'guest-rsvp-approved');
    }

    /**
     * plans/rsvp-status-changes.md Phase 5 — the host records a guest's answer for them, at any time (after the deadline,
     * after the event started, while checked in). The seat limit still applies unless the host ticks "allow over the
     * guest limit"; the guest is only told if the host ticks "tell the guest".
     */
    public function setRsvp(SetGuestRsvpRequest $request, Event $event, Guest $guest, HostRsvpOverrideService $override): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $status = RsvpStatus::from($request->validated('status'));

        try {
            $rsvp = $override->set(
                $event,
                $guest,
                $status,
                (int) ($request->validated('attendee_count') ?? 1),
                $request->user(),
                allowOverLimit: $request->boolean('allow_over_limit'),
                notifyGuest: $request->boolean('notify_guest'),
            );
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors(), 'rsvpSet');
        }

        return back()->with('status', $rsvp->submissionChanged ? 'guest-rsvp-set' : 'guest-rsvp-set-unchanged');
    }

    /**
     * plans/plus-one-edge-cases.md Phase 1 — takes a confirmed guest back to one seat and tells them.
     * Switching plus-ones off never does this on its own; the host chooses it per guest.
     */
    public function removePlusOne(
        Event $event,
        Guest $guest,
        RsvpSubmissionService $submissions,
        CommunicationService $communication,
    ): RedirectResponse {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        if ($submissions->removePlusOne($event, $guest) === null) {
            return back()->withErrors(['plus_one' => 'This guest has no confirmed plus-one to remove.']);
        }

        try {
            $communication->sendPlusOneRemoved($event, $guest);
        } catch (\Throwable $e) {
            // The seat change is already saved; a mail failure must not undo or hide it.
            report($e);
        }

        return back()->with('status', 'guest-plus-one-removed');
    }

    /**
     * plans/rsvp-host-approval.md — declines a Pending RSVP; the guest is notified with the
     * host's note and never gets a confirmation or entry pass for this response.
     */
    public function rejectRsvp(RejectRsvpApprovalRequest $request, Event $event, Guest $guest, RsvpApprovalService $rsvpApprovalService): RedirectResponse
    {
        $guest->loadMissing('event', 'rsvp');

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);
        abort_if($guest->rsvp === null, 404);

        try {
            $rsvpApprovalService->reject($guest->rsvp, $event->user, $request->validated('host_rejection_note'));
        } catch (RsvpApprovalException $e) {
            return back()->withErrors(['rsvp_approval' => $e->getMessage()]);
        }

        return back()->with('status', 'guest-rsvp-rejected');
    }

    /**
     * Server-initiated WhatsApp send via Twilio — see plans/whatsapp-invitations.md. Gated the same
     * way as Print QR badges / check-in on this page: Pro and above
     * (Event::ownerHasPremiumEventTools()), since each send has a real per-message cost unlike the
     * free wa.me link the "WhatsApp" menu item next to this one still offers regardless of tier.
     */
    public function sendWhatsAppInvitation(Event $event, Guest $guest, CommunicationService $communicationService): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);
        abort_unless($event->ownerHasPremiumEventTools(), 403);

        $outcome = $communicationService->sendWhatsAppInvitation($event, $guest);

        return back()->with('status', match ($outcome) {
            'sent' => 'guest-whatsapp-sent',
            'invalid_phone' => 'guest-whatsapp-invalid-phone',
            'rate_limited' => 'guest-whatsapp-rate-limited',
            'disabled' => 'guest-whatsapp-disabled',
            'unpublished' => 'guest-whatsapp-unpublished',
            'closed' => 'guest-whatsapp-closed',
            default => 'guest-whatsapp-failed',
        });
    }

    public function qr(Event $event, Guest $guest, QrCodeService $qrCodeService): Response
    {
        $guest->loadMissing('event.user');
        $this->authorize('update', $guest);
        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);
        abort_unless($event->ownerHasPremiumEventTools(), 403);

        $url = $guest->checkInQrUrl();
        abort_if($url === null, 404);

        $svg = $qrCodeService->svg($url);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function qrSheet(Event $event, QrCodeService $qrCodeService): Response|RedirectResponse
    {
        $this->authorizeInvitation($event);

        // Navigable link, so send the host to billing the way the tables page
        // does rather than dead-ending on a 403. The per-guest qr() endpoint
        // above stays a hard 403 — it serves an image, not a page.
        if (! $event->ownerHasPremiumEventTools()) {
            return redirect()->route('billing.show')->with('status', 'premium-required-qr-badges');
        }

        $guests = $event->guests()
            ->whereNotNull('invitation_token')
            // No badge for someone who declined or whom the host rejected: the scanner would refuse it.
            ->wantedAtTheDoor()
            ->with('eventTable')
            ->orderBy('name')
            ->get()
            ->map(fn (Guest $guest) => [
                'name' => $guest->name,
                'table_label' => $guest->tableLabel(),
                'qr_data_uri' => 'data:image/svg+xml;base64,'.base64_encode($qrCodeService->svg((string) $guest->checkInQrUrl(), 220)),
            ]);

        $pdf = Pdf::loadView('events.guests.qr-sheet', compact('event', 'guests'))
            ->setPaper('a4', 'portrait');

        return $pdf->download('guest-qr-codes-'.$event->slug.'.pdf');
    }

    public function destroy(Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('delete', $guest);
        abort_unless($event->isInvitation(), 404);

        $guest->delete();

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-deleted');
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
