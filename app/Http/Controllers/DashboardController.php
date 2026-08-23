<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\DashboardAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analyticsService): View
    {
        $user = $request->user();

        // Events this user has accepted staff access on (Phase 18) — kept
        // out of $analyticsService->forUser(), which is scoped to owned
        // events only. A Check-in staffer has no business seeing another
        // host's RSVP/guest analytics just because they can scan the door.
        $staffing = Event::query()
            ->whereHas('staff', fn ($query) => $query
                ->where('user_id', $user->id)
                ->whereNotNull('accepted_at'))
            ->orderByDesc('event_date')
            ->limit(5)
            ->get();

        return view('dashboard', [
            'user' => $user,
            'analytics' => $analyticsService->forUser($user),
            'staffing' => $staffing,
        ]);
    }
}
