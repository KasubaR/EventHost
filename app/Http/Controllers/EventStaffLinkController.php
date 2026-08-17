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

        return redirect()->to($this->scanRouteFor($event))->with('status', 'staff-link-created');
    }

    public function destroy(Event $event, EventStaffLink $link): RedirectResponse
    {
        abort_unless($link->event_id === $event->id, 404);
        $this->authorize('delete', $link);

        $link->delete();

        return redirect()->to($this->scanRouteFor($event))->with('status', 'staff-link-revoked');
    }

    /**
     * This controller is already generic (an EventStaffLink is just
     * event_id/token/label, no guest-specific column) — the only thing that
     * ever differed per credential type was which dashboard page to bounce
     * back to, so that's the only branch added here.
     */
    private function scanRouteFor(Event $event): string
    {
        return $event->isTicketed()
            ? route('events.tickets.checkin.scan', $event)
            : route('events.checkin.scan', $event);
    }
}
