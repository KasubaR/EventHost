<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function show(Request $request, Event $event, InvitationCustomizationService $customizationService): View|RedirectResponse
    {
        $this->authorize('view', $event);

        if ($event->invitation_template_id === null) {
            return redirect()->route('events.choose-template', $event)
                ->with('status', 'pick-layout-to-preview');
        }

        $rsvpOpen = $event->isRsvpOpen();
        $rsvpPublicAvailable = $event->is_public && $rsvpOpen;
        $invitation = $customizationService->merge($event);

        // The preview link lives on both the edit page and the (read-only) show
        // page, so "back" has to return wherever the host actually came from —
        // otherwise a host who never opened the edit form lands there anyway.
        $back = $request->query('from') === 'show'
            ? ['route' => route('events.show', $event), 'label' => 'Back to event']
            : ['route' => route('events.edit', $event), 'label' => 'Back to edit'];

        // Deliberately does not touch invitation_views_count — that counter is
        // real guest traffic, and the host reviewing their own draft is not a view.
        return view('events.preview', compact('event', 'rsvpOpen', 'rsvpPublicAvailable', 'invitation', 'back'));
    }
}
