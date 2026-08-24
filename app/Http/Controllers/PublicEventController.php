<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Services\PublicInvitationResolver;
use App\Support\EventIcsDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    /**
     * Public listing of every upcoming event hosts have made public.
     * Reached from the homepage strip's "See all" link and the site nav.
     */
    public function index(): View
    {
        $events = Event::query()
            ->publiclyListed()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->paginate(12);

        return view('events.discover', compact('events'));
    }

    public function show(
        string $slug,
        InvitationCustomizationService $customizationService,
        PublicInvitationResolver $resolver,
    ): View|RedirectResponse {
        $resolved = $resolver->resolveInvitationPage($slug);

        if ($resolved instanceof RedirectResponse || $resolved instanceof View) {
            return $resolved;
        }

        $event = $resolved;

        // Ticketed events skip the invitation-template system entirely and
        // render the one fixed public template — no theme merge needed.
        if ($event->isTicketed()) {
            Event::query()->whereKey($event->getKey())->increment('invitation_views_count');

            return view('events.tickets.landing', compact('event'));
        }

        $event->loadMissing('invitationTemplate');

        $rsvpOpen = $event->isRsvpOpen();
        $rsvpPublicAvailable = $event->is_public && $rsvpOpen;
        $invitation = $customizationService->merge($event);

        Event::query()->whereKey($event->getKey())->increment('invitation_views_count');

        return view('events.public', compact('event', 'rsvpOpen', 'rsvpPublicAvailable', 'invitation'));
    }

    public function ics(string $slug, PublicInvitationResolver $resolver): Response|RedirectResponse
    {
        $resolved = $resolver->resolveInvitationPage($slug);

        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        // Status pages and drafts have no calendar attachment.
        if ($resolved instanceof View) {
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
