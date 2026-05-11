<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGuestGroupRequest;
use App\Http\Requests\UpdateGuestGroupRequest;
use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GuestGroupController extends Controller
{
    public function index(Event $event): View
    {
        $this->authorize('update', $event);

        $groups = $event->guestGroups()->withCount('guests')->get();

        return view('events.guest-groups.index', compact('event', 'groups'));
    }

    public function store(StoreGuestGroupRequest $request, Event $event): RedirectResponse
    {
        $event->guestGroups()->create([
            'name' => $request->validated()['name'],
        ]);

        return redirect()
            ->route('events.guest-groups.index', $event)
            ->with('status', 'guest-group-created');
    }

    public function update(UpdateGuestGroupRequest $request, Event $event, GuestGroup $guest_group): RedirectResponse
    {
        abort_unless($guest_group->event_id === $event->id, 404);

        $guest_group->update([
            'name' => $request->validated()['name'],
        ]);

        return redirect()
            ->route('events.guest-groups.index', $event)
            ->with('status', 'guest-group-updated');
    }

    public function destroy(Event $event, GuestGroup $guest_group): RedirectResponse
    {
        abort_unless($guest_group->event_id === $event->id, 404);

        $this->authorize('delete', $guest_group);

        $guest_group->delete();

        return redirect()
            ->route('events.guest-groups.index', $event)
            ->with('status', 'guest-group-deleted');
    }
}
