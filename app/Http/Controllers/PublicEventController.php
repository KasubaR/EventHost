<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\InvitationCustomizationService;
use App\Services\PublicInvitationResolver;
use App\Support\EventIcsDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    /**
     * Date-range presets for the discover filter bar. Values are the query
     * string tokens; 'any' (the default) applies no extra bound beyond
     * scopeUpcoming()'s own "today or later".
     *
     * @var list<string>
     */
    private const WHEN_OPTIONS = ['today', 'week', 'month'];

    /**
     * Public listing of every upcoming event hosts have made public.
     * Reached from the homepage strip's "See all" link and the site nav.
     *
     * Filters are plain query-string GET params (?q=&type=&when=&where=), same
     * convention as Admin\EventController's search — cheap to bookmark/share
     * and ->withQueryString() keeps them across pagination. `type` uses the
     * EVENT_TYPES union (not PUBLIC_EVENT_TYPES): a free-registration event's
     * type comes from privateEventTypes() (Event::eventTypesFor() is keyed on
     * product_kind, not audience), so both wedding-style and ticketed-style
     * values can legitimately appear here side by side.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));
        $when = trim((string) $request->query('when', ''));
        $where = trim((string) $request->query('where', ''));

        $events = Event::query()
            ->publiclyListed()
            ->upcoming()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when(
                in_array($type, Event::EVENT_TYPES, true),
                fn ($query) => $query->where('event_type', $type)
            )
            ->when($where !== '', function ($query) use ($where): void {
                $query->where(function ($q) use ($where): void {
                    $q->where('venue', 'like', '%'.$where.'%')
                        ->orWhere('location_name', 'like', '%'.$where.'%');
                });
            })
            ->when(in_array($when, self::WHEN_OPTIONS, true), function ($query) use ($when): void {
                $query->whereDate('event_date', '<=', match ($when) {
                    'today' => today(),
                    'week' => today()->addDays(7),
                    'month' => today()->endOfMonth(),
                });
            })
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->paginate(12)
            ->withQueryString();

        return view('events.discover', [
            'events' => $events,
            'search' => $search,
            'type' => $type,
            'when' => $when,
            'where' => $where,
            'eventTypes' => Event::EVENT_TYPES,
            'hasActiveFilters' => $search !== '' || $type !== '' || $when !== '' || $where !== '',
        ]);
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
        // The ticketed branch above already returned, so every event reaching here
        // is invitation-kind — private ones now get the same inline/open-RSVP form
        // as public ones, just never listed anywhere. See PublicInvitationResolver.
        $rsvpPublicAvailable = $rsvpOpen;
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
