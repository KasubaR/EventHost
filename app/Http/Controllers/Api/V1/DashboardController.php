<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventListResource;
use App\Models\Event;
use App\Services\DashboardAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON sibling of App\Http\Controllers\DashboardController. Deliberately drops
 * pendingCustomQuote — the Enterprise/Contact-Sales quote flow was explicitly
 * excluded from the Android app's scope. The web controller is untouched.
 */
class DashboardController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analyticsService): JsonResponse
    {
        $user = $request->user();

        // Events this user has accepted staff access on — separate from
        // $analyticsService->forUser(), which is ownership-scoped. A Check-in
        // staffer has no business seeing another host's RSVP/guest analytics
        // just because they can scan the door. Verbatim copy of the web query.
        $staffing = Event::query()
            ->whereHas('staff', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNotNull('accepted_at'))
            ->orderByDesc('event_date')
            ->limit(5)
            ->get();

        return response()->json([
            'analytics' => $analyticsService->forUser($user),
            'staffing' => EventListResource::collection($staffing),
        ]);
    }
}
