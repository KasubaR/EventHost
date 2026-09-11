<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PublicInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PublicEventListResource;
use App\Http\Resources\Api\V1\PublicEventResource;
use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Services\PublicInvitationResolver;
use App\Support\EventIcsDocument;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;

/**
 * JSON sibling of App\Http\Controllers\PublicEventController, for invitation-kind events only.
 * Ticketed-event JSON is a later slice — see the ticketed branch in show() below.
 */
class PublicEventController extends Controller
{
    /**
     * Same scope chain as web's PublicEventController::index() and HomeController::index()
     * (confirmed identical — no other filtering exists anywhere for the public listing).
     */
    public function index(): AnonymousResourceCollection
    {
        $events = Event::query()
            ->publiclyListed()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->paginate(12);

        return PublicEventListResource::collection($events);
    }

    public function show(
        string $slug,
        InvitationCustomizationService $customizationService,
        PublicInvitationResolver $resolver,
    ): PublicEventResource|JsonResponse|RedirectResponse {
        $resolved = $resolver->resolveInvitationPageForApi($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        if ($resolved instanceof PublicInvitationStatus) {
            return response()->json([
                'status' => $resolved->value,
                'title' => $resolved->title(),
                'message' => $resolved->message(),
            ]);
        }

        $event = $resolved;

        // Ticketed events have no invitation-template/customization to merge — a later slice
        // covers their public JSON. The event genuinely exists and resolves, so this is a
        // minimal pointer (200), not a 404, which would be indistinguishable from "no such event".
        if ($event->isTicketed()) {
            Event::query()->whereKey($event->getKey())->increment('invitation_views_count');

            return response()->json([
                'slug' => $event->slug,
                'name' => $event->name,
                'product_kind' => 'ticketed',
                'public_url' => route('events.public', ['slug' => $event->slug]),
            ]);
        }

        $event->loadMissing('invitationTemplate');

        $rsvpOpen = $event->isRsvpOpen();
        $rsvpPublicAvailable = $event->is_public && $rsvpOpen;
        $invitation = $customizationService->merge($event);

        Event::query()->whereKey($event->getKey())->increment('invitation_views_count');

        return new PublicEventResource($event, $invitation, $rsvpOpen, $rsvpPublicAvailable);
    }

    public function ics(string $slug, PublicInvitationResolver $resolver): Response|RedirectResponse
    {
        $resolved = $resolver->resolveInvitationPageForApi($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        // Status pages and drafts have no calendar attachment — same as web.
        if ($resolved instanceof PublicInvitationStatus) {
            abort(404);
        }

        $event = $resolved;

        $body = EventIcsDocument::build($event);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.EventIcsDocument::filename($event).'"',
        ]);
    }
}
