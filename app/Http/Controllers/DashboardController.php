<?php

namespace App\Http\Controllers;

use App\Enums\EventAudience;
use App\Models\CustomQuote;
use App\Models\Event;
use App\Services\DashboardAnalyticsService;
use App\Services\PublicDashboardAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Two overviews, one per portal (plans/public-private-portals.md Phase 3):
 * index() is the private portal's /dashboard, unchanged in shape from before
 * the split except that it now only counts private-audience events.
 * publicOverview() is the new /public-dashboard, commerce-shaped instead of
 * guest/RSVP-shaped — see PublicDashboardAnalyticsService's own docblock for
 * why that isn't just this same analytics service with a different filter.
 */
class DashboardController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analyticsService): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'analytics' => $analyticsService->forUser($user, EventAudience::Private),
            'pendingCustomQuote' => CustomQuote::pendingFor($user),
        ]);
    }

    public function publicOverview(
        Request $request,
        PublicDashboardAnalyticsService $analyticsService,
        TicketRevenueLedgerService $ledger,
    ): View {
        $user = $request->user();

        // Events this user has accepted staff access on (Phase 18) — kept out
        // of the owned-events analytics above it. A check-in staffer has no
        // business seeing another host's revenue just because they can scan
        // the door. Staff access is ticketed-only (EventStaffController's own
        // docblock), i.e. always a public-audience event, so this belongs on
        // the public overview, not the private one.
        $staffing = Event::query()
            ->whereHas('staff', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNotNull('accepted_at'))
            ->orderByDesc('event_date')
            ->limit(5)
            ->get();

        // Phase 6 item 3 of plans/public-private-portals.md: the handful of
        // pre-existing events the audience backfill silently moved here.
        // whereNull is the live equivalent of Event::needsAudienceMigrationNotice().
        $migratedEvents = Event::query()
            ->where('user_id', $user->id)
            ->whereNull('audience_migration_notice_seen_at')
            ->orderBy('name')
            ->get();

        return view('public-dashboard', [
            'user' => $user,
            'analytics' => $analyticsService->forUser($user, $ledger),
            'staffing' => $staffing,
            'pendingCustomQuote' => CustomQuote::pendingFor($user),
            'migratedEvents' => $migratedEvents,
        ]);
    }
}
