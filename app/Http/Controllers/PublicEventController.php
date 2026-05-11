<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Support\EventIcsDocument;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function show(string $slug, InvitationCustomizationService $customizationService): View
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        if (! $event->is_public) {
            abort(403);
        }

        $event->loadMissing('invitationTemplate');

        $rsvpOpen = $event->isRsvpOpen();
        $rsvpPublicAvailable = $event->is_public && $rsvpOpen;
        $invitation = $customizationService->merge($event);

        Event::query()->whereKey($event->getKey())->increment('invitation_views_count');

        return view('events.public', compact('event', 'rsvpOpen', 'rsvpPublicAvailable', 'invitation'));
    }

    public function ics(string $slug): Response
    {
        $event = Event::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        if (! $event->is_public) {
            abort(403);
        }

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
