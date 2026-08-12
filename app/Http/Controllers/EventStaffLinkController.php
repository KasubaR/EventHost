<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventStaffLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventStaffLinkController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);
        abort_unless($event->ownerHasPremiumEventTools(), 403);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $label = trim((string) ($validated['label'] ?? ''));

        $event->staffLinks()->create([
            'label' => $label !== '' ? $label : null,
        ]);

        return redirect()->route('events.checkin.scan', $event)->with('status', 'staff-link-created');
    }

    public function destroy(Event $event, EventStaffLink $link): RedirectResponse
    {
        abort_unless($link->event_id === $event->id, 404);
        $this->authorize('delete', $link);

        $link->delete();

        return redirect()->route('events.checkin.scan', $event)->with('status', 'staff-link-revoked');
    }
}
