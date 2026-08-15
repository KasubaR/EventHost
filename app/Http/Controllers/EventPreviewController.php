<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class EventPreviewController extends Controller
{
    /**
     * Host-only view of the event's real, current invitation design —
     * regardless of is_published/is_public. Those flags gate the public
     * /e/{slug} route; this route is gated on ownership instead, so a host
     * can review a draft before publishing, or a private event that never
     * gets a public link at all.
     */
    public function show(Event $event, InvitationCustomizationService $customizationService): View|RedirectResponse
    {
        $this->authorize('view', $event);

        if ($event->invitation_template_id === null) {
            return redirect()->route('events.choose-template', $event)
                ->with('status', 'pick-layout-to-preview');
        }

        $rsvpOpen = $event->isRsvpOpen();
        $rsvpPublicAvailable = $event->is_public && $rsvpOpen;
        $invitation = $customizationService->merge($event);

        // Deliberately does not touch invitation_views_count — that counter is
        // real guest traffic, and the host reviewing their own draft is not a view.
        return view('events.preview', compact('event', 'rsvpOpen', 'rsvpPublicAvailable', 'invitation'));
    }
}
