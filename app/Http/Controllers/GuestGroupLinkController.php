<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * plans/group-rsvp-links.md — the host's controls for a group's shared RSVP link:
 * turn it on / change the seat pool, close or reopen it, turn it off.
 */
class GuestGroupLinkController extends Controller
{
    public function update(Request $request, Event $event, GuestGroup $guest_group): RedirectResponse
    {
        $this->guard($event, $guest_group);

        $data = $request->validate([
            'seat_limit' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $guest_group->enableLink((int) $data['seat_limit']);

        return $this->back($event, 'guest-group-link-saved');
    }

    public function toggle(Event $event, GuestGroup $guest_group): RedirectResponse
    {
        $this->guard($event, $guest_group);
        abort_unless($guest_group->hasSeatPool(), 404);

        $guest_group->forceFill([
            'rsvp_link_closed_at' => $guest_group->rsvp_link_closed_at === null ? now() : null,
        ])->save();

        return $this->back($event, $guest_group->rsvp_link_closed_at === null ? 'guest-group-link-reopened' : 'guest-group-link-closed');
    }

    public function destroy(Event $event, GuestGroup $guest_group): RedirectResponse
    {
        $this->guard($event, $guest_group);

        $guest_group->disableLink();

        return $this->back($event, 'guest-group-link-removed');
    }

    private function guard(Event $event, GuestGroup $group): void
    {
        abort_unless($group->event_id === $event->id, 404);
        abort_unless($event->isInvitation(), 404);

        $this->authorize('update', $group);
    }

    private function back(Event $event, string $status): RedirectResponse
    {
        return redirect()->route('events.guest-groups.index', $event)->with('status', $status);
    }
}
