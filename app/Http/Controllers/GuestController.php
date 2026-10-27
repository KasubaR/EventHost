<?php

namespace App\Http\Controllers;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\RsvpApprovalException;
use App\Http\Requests\RejectRsvpApprovalRequest;
use App\Http\Requests\SetGuestRsvpRequest;
use App\Http\Requests\StoreGuestRequest;
use App\Http\Requests\UpdateGuestRequest;
use App\Models\EmailSuppression;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestEdit;
use App\Models\GuestImport;
use App\Models\GuestRemoval;
use App\Models\NotificationLog;
use App\Services\CommunicationService;
use App\Services\EmailDeliveryProblems;
use App\Services\GuestCreator;
use App\Services\GuestDeleter;
use App\Services\GuestUpdater;
use App\Services\HostRsvpOverrideService;
use App\Services\QrCodeService;
use App\Services\RsvpApprovalService;
use App\Services\RsvpSubmissionService;
use App\Services\WhatsAppDeliveryStatusService;
use App\Services\WhatsAppShareService;
use App\Support\EmailTypo;
use App\Support\GuestPhone;
use App\Support\SpreadsheetCell;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        $whatsAppShare = app(WhatsAppShareService::class);
        $shareBlockedReason = $whatsAppShare->eventBlockedReason($event);
        $pageGuestIds = $guests->getCollection()->pluck('id')->all();
        $lastShared = $whatsAppShare->lastOpened($event, $pageGuestIds);
        $undeliveredWhatsApp = app(WhatsAppDeliveryStatusService::class)->undeliveredInvitations($event, $pageGuestIds);
        $emailProblems = app(EmailDeliveryProblems::class);

        // A background import shows here while it runs, and its result once, the first time the list is opened after it finished.
        $latestImport = GuestImport::query()->where('event_id', $event->id)->latest('id')->first();
        $unseenImport = $latestImport?->isFinished() && $latestImport->seen_at === null ? $latestImport : null;
        $unseenImport?->forceFill(['seen_at' => now()])->save();

        // Who the host removed lately, with a way to tell them (plans/guest-deletion-gaps.md Phase 5).
        $removedGuests = $event->guestRemovals()->orderByDesc('removed_at')->orderByDesc('id')->limit(GuestRemoval::LIST_LIMIT)->get();

        return view('events.guests.index', compact('event', 'guests', 'filter', 'groups', 'tables', 'stats', 'whatsappSendEnabled', 'latestImport', 'unseenImport', 'removedGuests', 'whatsAppShare', 'shareBlockedReason', 'lastShared', 'undeliveredWhatsApp', 'emailProblems'));
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
            fwrite($handle, SpreadsheetCell::BOM);

            fputcsv($handle, [
                'Name', 'Email', 'Phone', 'Group',
                'RSVP Status', 'Attendee Count', 'Message',
                'Invitation Sent', 'Invitation Sent At',
                'Checked In At', 'Checked In By', 'Response Last Changed',
            ]);

            $guestsQuery->chunk(200, function ($chunk) use ($handle) {
                foreach ($chunk as $guest) {
                    $rsvp = $guest->rsvp;
                    fputcsv($handle, SpreadsheetCell::row([
                        $guest->name,
                        $guest->email ?? '',
                        GuestPhone::display($guest->phone) ?? '',
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
                    ]));
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
            ->with('guest_same_name', Guest::sameNameCount($event, $guest->name, ignoreGuestId: $guest->id) > 0 ? $guest->name : null)
            ->with('guest_shared_phone', Guest::matchingPhone($event, $guest->phone, ignoreGuestId: $guest->id)?->name)
            ->with('email_typo', self::emailTypoFlash($event, $guest));
    }

    /**
     * What the list shows when a saved address looks like a typo of a common provider: the address, the fix, and the guest's edit page
     * with the fix pre-filled. Null when there is nothing to say. The address is saved as typed either way.
     *
     * @return array{guest: string, email: string, suggestion: string, url: string}|null
     */
    private static function emailTypoFlash(Event $event, Guest $guest): ?array
    {
        $suggestion = EmailTypo::suggest($guest->email);

        if ($suggestion === null) {
            return null;
        }

        return [
            'guest' => $guest->name,
            'email' => (string) $guest->email,
            'suggestion' => $suggestion,
            'url' => route('events.guests.edit', ['event' => $event, 'guest' => $guest, 'email' => $suggestion]),
        ];
    }

    public function edit(Event $event, Guest $guest): View
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);
        abort_unless($event->isInvitation(), 404);

        $groups = $event->guestGroups()->get();
        $tables = $event->tables()->orderBy('sort_order')->orderBy('label')->get();
        $changes = $guest->rsvpChanges()->with('actor')->latest('id')->limit(30)->get();
        $edits = $guest->edits()->with('user')->latest('id')->limit(20)->get();

        // Another guest on the same number (a household), shown on the edit page so the host sees why.
        $sharedWith = Guest::matchingPhone($event, $guest->phone, ignoreGuestId: $guest->id);

        return view('events.guests.edit', compact('event', 'guest', 'groups', 'tables', 'changes', 'edits', 'sharedWith'));
    }

    public function update(UpdateGuestRequest $request, Event $event, Guest $guest, GuestUpdater $updater): RedirectResponse
    {
        $guest->loadMissing('event');
        abort_unless($event->isInvitation(), 404);

        $result = $updater->update($event, $guest, $request->validated(), $request->user()?->id);
        $guest = $result['guest'];

        // Their confirmed plus-one stays; say so, since the checkbox suggests otherwise.
        if ($result['plus_one_switched_off'] && $guest->rsvp?->heldSeats() >= 2) {
            session()->flash('plus_one_kept', $guest->name);
        }

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-updated')
            ->with('email_typo', self::emailTypoFlash($event, $guest))
            // The new contact has had no invitation: the list no longer says "Sent".
            ->with('guest_contact_reset', $result['invitation_reset'] ? $guest->name : null)
            // Plus-one switched on: nothing changes for the guest until they reopen the form, so offer to tell them. Or, when the event's
            // own switch is off, say the tick does nothing yet.
            ->with('plus_one_offered', $result['plus_one_switched_on'] && $event->allow_plus_one && $this->canBePromptedAboutPlusOne($guest) ? [
                'guest' => $guest->name,
                'url' => route('events.guests.notify-plus-one', ['event' => $event, 'guest' => $guest]),
                'has_email' => filled($guest->email),
            ] : null)
            ->with('plus_one_inactive', $result['plus_one_switched_on'] && ! $event->allow_plus_one ? $guest->name : null)
            // The old address can still open the link; offer to replace it (a QR already delivered stops working, so never automatic).
            ->with('old_link_still_works', $result['old_link_still_works'] ? [
                'guest' => $guest->name,
                'url' => route('events.guests.regenerate-link', ['event' => $event, 'guest' => $guest]),
            ] : null)
            ->with('guest_link_replaced', $result['link_replaced'] ? [
                'guest' => $guest->name,
                'link' => (string) $guest->personalRsvpUrl(),
            ] : null);
    }

    /** A guest the plus-one news matters to: they have said yes with one seat, so they may not know they could bring someone. */
    private function canBePromptedAboutPlusOne(Guest $guest): bool
    {
        return $guest->rsvp !== null && $guest->rsvp->heldSeats() === 1;
    }

    /**
     * "Tell them": one email saying this guest can now bring a plus-one, with their link. Never automatic. Refused with no email on file,
     * while RSVP is closed (the link would lead to a closed form), when the plus-one is not actually available to them, and when they were
     * already told in the last hour. plans/guest-editing-gaps.md Phase 4.
     */
    public function notifyPlusOne(Event $event, Guest $guest, CommunicationService $communications): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);
        abort_unless($event->isInvitation(), 404);

        $back = redirect()->route('events.guests.index', $event);

        if (! $event->allow_plus_one || ! $guest->plus_one_allowed) {
            return $back->withErrors(['plus_one' => $guest->name.' cannot bring a plus-one yet: switch plus-ones on for the event and for this guest first.']);
        }

        if (! filled($guest->email)) {
            return $back->withErrors(['plus_one' => $guest->name.' has no email address, so tell them yourself.']);
        }

        if (EmailSuppression::isSuppressed($guest->email)) {
            return $back->withErrors(['plus_one' => 'Email to '.$guest->name.' bounced, so nothing was sent. Check the address, or tell them yourself.']);
        }

        if (($reason = $event->rsvpClosedReason()) !== null) {
            return $back->withErrors(['plus_one' => $reason.' Their link would lead to a closed form, so nothing was sent.']);
        }

        $recently = NotificationLog::query()
            ->where('guest_id', $guest->id)
            ->where('type', 'plus_one_offer')
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if ($recently) {
            return $back->withErrors(['plus_one' => $guest->name.' was already told in the last hour.']);
        }

        $communications->sendEventUpdate(
            $event,
            $guest,
            'You can now bring one guest. Open your invitation and update your RSVP to add them.',
            'plus_one_offer',
        );

        return $back->with('status', 'plus-one-offer-sent')->with('plus_one_told', $guest->name);
    }

    /**
     * "Tell them": the one email telling a removed guest they were removed. Never automatic. Works from the tombstone because the
     * guest row is gone; once sent it cannot be sent again. plans/guest-deletion-gaps.md Phase 5.
     */
    public function notifyRemoved(Event $event, GuestRemoval $removal, CommunicationService $communications): RedirectResponse
    {
        $this->authorizeInvitation($event);
        abort_unless($removal->event_id === $event->id, 404);

        $back = redirect()->route('events.guests.index', $event);

        if ($removal->notified_at !== null) {
            return $back->withErrors(['removed' => $removal->name.' was already told.']);
        }

        if (blank($removal->email)) {
            return $back->withErrors(['removed' => $removal->name.' has no email address, so tell them yourself.']);
        }

        if (EmailSuppression::isSuppressed($removal->email)) {
            return $back->withErrors(['removed' => 'Email to '.$removal->name.' bounced before, so nothing was sent. Tell them yourself.']);
        }

        if (! $removal->canBeTold()) {
            return $back->withErrors(['removed' => $removal->name.' was never sent an invitation or answered, so there is nothing to tell them.']);
        }

        $communications->sendGuestRemoved($event, $removal);

        return $back->with('status', 'removed-guest-told')->with('removed_told', $removal->name);
    }

    /**
     * "Try this address again": the host has checked a bounced address and says it is right, so it comes off the bounced list
     * (for every event, as the list is). The next bounce puts it back. Owner only. plans/email-gaps.md Phase 3.
     */
    public function retryEmail(Event $event, Guest $guest): RedirectResponse
    {
        $this->authorize('pause', $event);
        abort_unless($event->isInvitation() && $guest->event_id === $event->id, 404);

        EmailSuppression::forEmail($guest->email)?->delete();
        app(EmailDeliveryProblems::class)->forget($event->id);

        return redirect()->route('events.guests.index', $event)
            ->with('status', 'email-retry')
            ->with('email_retry', $guest->name);
    }

    /**
     * Replace one guest's personal link now (the one-click follow-up to an email change). The old link, and the QR on any pass already
     * sent or printed, stop working at once; the new link is shown for the host to send. plans/guest-editing-gaps.md Phase 2.
     */
    public function regenerateLink(Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);
        abort_unless($event->isInvitation(), 404);

        DB::transaction(function () use ($event, $guest): void {
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            Guest::query()->whereKey($guest->id)->lockForUpdate()->firstOrFail()
                ->forceFill(['invitation_token' => Str::random(48)])->save();
            GuestEdit::record($guest, request()->user()?->id, ['link_replaced' => true]);
        });

        $guest->refresh();

        return redirect()
            ->route('events.guests.index', $event)
            ->with('guest_link_replaced', ['guest' => $guest->name, 'link' => (string) $guest->personalRsvpUrl()]);
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
    public function sendWhatsAppInvitation(Request $request, Event $event, Guest $guest, CommunicationService $communicationService): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);
        abort_unless($event->ownerHasPremiumEventTools(), 403);

        $outcome = $communicationService->sendWhatsAppInvitation($event, $guest, $request->boolean('resend'));

        if ($outcome === 'already_sent') {
            $previous = $communicationService->lastWhatsAppInvitation($event, $guest);

            return back()->with('whatsapp_already_sent', [
                'guest_id' => $guest->id,
                'name' => $guest->name,
                'sent_at' => ($previous?->sent_at ?? $previous?->created_at)?->toIso8601String(),
                'in_flight' => $previous?->status === NotificationLog::STATUS_PENDING,
            ]);
        }

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

    public function destroy(Event $event, Guest $guest, GuestDeleter $guestDeleter): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('delete', $guest);
        abort_unless($event->isInvitation(), 404);

        $group = $guest->group;
        $result = $guestDeleter->delete($event, $guest, request()->user()?->id);
        $removal = GuestRemoval::query()->where('event_id', $event->id)->where('token_hash', filled($guest->invitation_token) ? GuestRemoval::hashToken((string) $guest->invitation_token) : null)->latest('id')->first();

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-deleted')
            ->with('guest_removed', $result['deleted'] === 0 ? null : [
                'name' => $guest->name,
                'seats_freed' => $result['seats_freed'],
                'pool' => $group !== null && $group->hasSeatPool() ? ['group' => $group->name, 'left' => $group->seatsRemaining()] : null,
                'tell_url' => $removal?->canBeTold() ? route('events.guests.removed.notify', ['event' => $event, 'removal' => $removal]) : null,
            ]);
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
