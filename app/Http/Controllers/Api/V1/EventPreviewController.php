<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventPreviewResource;
use App\Models\Event;
use App\Services\InvitationCustomizationService;
use Illuminate\Http\JsonResponse;

/**
 * JSON sibling of App\Http\Controllers\EventPreviewController. Structured `reason`
 * fields (`is_ticketed`, `needs_template`) replace the web controller's redirects —
 * a mobile client can't follow a Blade route redirect. The web controller is
 * untouched by this class.
 */
class EventPreviewController extends Controller
{
    public function show(Event $event, InvitationCustomizationService $customizationService): EventPreviewResource|JsonResponse
    {
        $this->authorize('view', $event);

        if ($event->isTicketed()) {
            return response()->json(['reason' => 'is_ticketed'], 422);
        }

        if ($event->invitation_template_id === null) {
            return response()->json(['reason' => 'needs_template'], 422);
        }

        $rsvpOpen = $event->isRsvpOpen();
        // is_ticketed already returned above, so this is always an invitation
        // event — kept in step with the web EventPreviewController's own comment.
        $rsvpPublicAvailable = $rsvpOpen;
        $invitation = $customizationService->merge($event);

        // Deliberately does not touch invitation_views_count — that counter is
        // real guest traffic, and the host reviewing their own draft is not a view.
        return new EventPreviewResource($event, $invitation, $rsvpOpen, $rsvpPublicAvailable);
    }
}
