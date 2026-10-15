<?php

namespace App\Http\Controllers;

use App\Enums\RsvpApprovalStatus;
use App\Http\Requests\StoreGuestGroupRequest;
use App\Http\Requests\UpdateGuestGroupRequest;
use App\Models\Event;
use App\Models\GuestGroup;
use App\Services\GuestGroupDeleter;
use App\Services\GuestGroupNamer;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GuestGroupController extends Controller
{
    public function index(Event $event): View
    {
        $this->authorizeInvitation($event);

        $groups = $event->guestGroups()
            ->withCount([
                'guests',
                'guests as joiners_count' => fn ($q) => $q->whereNotNull('group_link_joined_at'),
                'guests as pending_count' => fn ($q) => $q->whereHas('rsvp', fn ($r) => $r->where('host_approval_status', RsvpApprovalStatus::Pending)),
            ])
            ->get();

        return view('events.guest-groups.index', compact('event', 'groups'));
    }

    public function store(StoreGuestGroupRequest $request, Event $event, GuestGroupNamer $namer): RedirectResponse
    {
        abort_unless($event->isInvitation(), 404);

        $namer->create($event, $request->validated()['name']);

        return redirect()
            ->route('events.guest-groups.index', $event)
            ->with('status', 'guest-group-created');
    }

    public function update(UpdateGuestGroupRequest $request, Event $event, GuestGroup $guest_group, GuestGroupNamer $namer): RedirectResponse
    {
        abort_unless($guest_group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $namer->rename($event, $guest_group, $request->validated()['name']);

        return redirect()
            ->route('events.guest-groups.index', $event)
            ->with('status', 'guest-group-updated');
    }

    public function destroy(Event $event, GuestGroup $guest_group, GuestGroupDeleter $deleter): RedirectResponse
    {
        abort_unless($guest_group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $this->authorize('delete', $guest_group);

        $removed = $deleter->delete($event, $guest_group, request()->user()?->id);

        return redirect()
            ->route('events.guest-groups.index', $event)
            ->with('status', 'guest-group-deleted')
            ->with('group_removed', $removed + ['kind' => 'deleted']);
    }

    private function authorizeInvitation(Event $event): void
    {
        $this->authorize('update', $event);

        abort_unless($event->isInvitation(), 404);
    }
}
