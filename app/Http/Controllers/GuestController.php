<?php

namespace App\Http\Controllers;

use App\Enums\RsvpStatus;
use App\Http\Requests\StoreGuestRequest;
use App\Http\Requests\UpdateGuestRequest;
use App\Models\Event;
use App\Models\Guest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestController extends Controller
{
    public function index(Request $request, Event $event): View
    {
        $this->authorize('update', $event);

        $groups = $event->guestGroups()->get();

        $filter = (string) $request->query('response', 'all');

        $guestsQuery = $event->guests()
            ->with(['rsvp', 'group'])
            ->search($request->query('q'))
            ->forGuestGroupFilter($request->query('group'))
            ->forInvitationSentFilter($request->query('invitation_sent'))
            ->forPlusOneFilter($request->query('plus_one'))
            ->orderBy('name');

        if ($filter === 'pending') {
            $guestsQuery->whereDoesntHave('rsvp');
        } elseif ($filter === 'responded') {
            $guestsQuery->whereHas('rsvp');
        } elseif ($filter === RsvpStatus::Accepted->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted));
        } elseif ($filter === RsvpStatus::Declined->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined));
        } elseif ($filter === RsvpStatus::Maybe->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Maybe));
        }

        $guests = $guestsQuery->paginate(30)->withQueryString();

        $stats = [
            'total' => $event->guests()->count(),
            'pending' => $event->guests()->whereDoesntHave('rsvp')->count(),
            'accepted' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted))->count(),
            'declined' => $event->guests()->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined))->count(),
        ];

        return view('events.guests.index', compact('event', 'guests', 'filter', 'groups', 'stats'));
    }

    public function export(Request $request, Event $event): StreamedResponse
    {
        $this->authorize('update', $event);

        $filter = (string) $request->query('response', 'all');

        $guestsQuery = $event->guests()
            ->with(['rsvp', 'group'])
            ->search($request->query('q'))
            ->forGuestGroupFilter($request->query('group'))
            ->forInvitationSentFilter($request->query('invitation_sent'))
            ->forPlusOneFilter($request->query('plus_one'))
            ->orderBy('name');

        if ($filter === 'pending') {
            $guestsQuery->whereDoesntHave('rsvp');
        } elseif ($filter === 'responded') {
            $guestsQuery->whereHas('rsvp');
        } elseif ($filter === RsvpStatus::Accepted->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted));
        } elseif ($filter === RsvpStatus::Declined->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined));
        } elseif ($filter === RsvpStatus::Maybe->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Maybe));
        }

        $filename = 'guests-' . str($event->name)->slug() . '.csv';

        return response()->streamDownload(function () use ($guestsQuery) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Name', 'Email', 'Phone', 'Group',
                'RSVP Status', 'Attendee Count', 'Message',
                'Meal Preference', 'Transportation Note', 'Song Request',
                'Invitation Sent', 'Invitation Sent At',
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
                        $rsvp?->meal_preference ?? '',
                        $rsvp?->transportation_note ?? '',
                        $rsvp?->song_request ?? '',
                        $guest->invitation_sent ? 'Yes' : 'No',
                        $guest->invitation_sent_at?->format('Y-m-d H:i') ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function exportPdf(Request $request, Event $event): Response
    {
        $this->authorize('update', $event);

        $filter = (string) $request->query('response', 'all');

        $guestsQuery = $event->guests()
            ->with(['rsvp', 'group'])
            ->search($request->query('q'))
            ->forGuestGroupFilter($request->query('group'))
            ->forInvitationSentFilter($request->query('invitation_sent'))
            ->forPlusOneFilter($request->query('plus_one'))
            ->orderBy('name');

        if ($filter === 'pending') {
            $guestsQuery->whereDoesntHave('rsvp');
        } elseif ($filter === 'responded') {
            $guestsQuery->whereHas('rsvp');
        } elseif ($filter === RsvpStatus::Accepted->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Accepted));
        } elseif ($filter === RsvpStatus::Declined->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Declined));
        } elseif ($filter === RsvpStatus::Maybe->value) {
            $guestsQuery->whereHas('rsvp', fn ($q) => $q->where('status', RsvpStatus::Maybe));
        }

        $guests = $guestsQuery->get();

        $filterLabels = [
            'pending'   => 'Pending',
            'responded' => 'Responded',
            'accepted'  => 'Accepted',
            'declined'  => 'Declined',
            'maybe'     => 'Maybe',
        ];
        $filterLabel = $filter !== 'all' ? ($filterLabels[$filter] ?? null) : null;

        $filename = 'guests-' . str($event->name)->slug() . '.pdf';

        $pdf = Pdf::loadView('events.guests.export-pdf', compact('event', 'guests', 'filterLabel'))
            ->setPaper('a4', 'landscape');

        return $pdf->download($filename);
    }

    public function create(Event $event): View
    {
        $this->authorize('update', $event);

        $groups = $event->guestGroups()->get();

        return view('events.guests.create', compact('event', 'groups'));
    }

    public function store(StoreGuestRequest $request, Event $event): RedirectResponse
    {
        $validated = $request->validated();

        $markSent = $validated['mark_invitation_sent'] ?? false;

        Guest::query()->create([
            'event_id' => $event->id,
            'guest_group_id' => $validated['guest_group_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'invitation_token' => Str::random(48),
            'plus_one_allowed' => $validated['plus_one_allowed'] ?? false,
            'invitation_sent' => $markSent,
            'invitation_sent_at' => $markSent ? now() : null,
        ]);

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-created');
    }

    public function edit(Event $event, Guest $guest): View
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        $groups = $event->guestGroups()->get();

        return view('events.guests.edit', compact('event', 'guest', 'groups'));
    }

    public function update(UpdateGuestRequest $request, Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        $validated = $request->validated();

        $data = [
            'guest_group_id' => $validated['guest_group_id'] ?? null,
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

        $guest->fill($data)->save();

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-updated');
    }

    public function markInvitationSent(Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('update', $guest);

        abort_unless($guest->event_id === $event->id, 404);

        $guest->forceFill([
            'invitation_sent' => true,
            'invitation_sent_at' => now(),
        ])->save();

        return back()->with('status', 'guest-invitation-marked-sent');
    }

    public function destroy(Event $event, Guest $guest): RedirectResponse
    {
        $guest->loadMissing('event');
        $this->authorize('delete', $guest);

        $guest->delete();

        return redirect()
            ->route('events.guests.index', $event)
            ->with('status', 'guest-deleted');
    }
}
