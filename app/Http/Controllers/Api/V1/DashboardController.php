<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventAudience;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventListResource;
use App\Models\Event;
use App\Services\DashboardAnalyticsService;
use App\Services\PublicDashboardAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\DashboardController. Deliberately drops
 * pendingCustomQuote — the Enterprise/Contact-Sales quote flow was explicitly
 * excluded from the Android app's scope. The web controller is untouched.
 */
class DashboardController extends Controller
{
    public function index(
        Request $request,
        DashboardAnalyticsService $analyticsService,
        PublicDashboardAnalyticsService $publicAnalyticsService,
        TicketRevenueLedgerService $ledger,
    ): JsonResponse {
        $user = $request->user();
        // Optional private/public portal split. Omitted or unrecognised = every owned
        // event, exactly as before, so older app builds are unaffected.
        $audience = EventAudience::tryFrom((string) $request->query('audience'));

        // Events this user has accepted staff access on — separate from
        // $analyticsService->forUser(), which is ownership-scoped. A Check-in
        // staffer has no business seeing another host's RSVP/guest analytics
        // just because they can scan the door. Verbatim copy of the web query.
        $staffing = Event::query()
            ->whereHas('staff', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNotNull('accepted_at'))
            ->when($audience, fn ($query) => $query->forAudience($audience))
            ->orderByDesc('event_date')
            ->limit(5)
            ->get();

        $payload = [
            'analytics' => $analyticsService->forUser($user, $audience),
            'staffing' => EventListResource::collection($staffing),
        ];

        // The public portal is commerce-shaped (tickets, check-ins, revenue), not
        // guest/RSVP-shaped — same service the web's /public-dashboard uses. Only sent
        // for ?audience=public so the private and unfiltered responses stay unchanged.
        // `upcoming` is dropped: it is a Collection of models the app doesn't read here.
        if ($audience === EventAudience::Public) {
            $payload['public_totals'] = $publicAnalyticsService->forUser($user, $ledger)['totals'];
        }

        return response()->json($payload);
    }
}
