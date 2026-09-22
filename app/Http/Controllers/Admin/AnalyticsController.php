<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EventAudience;
use App\Http\Controllers\Controller;
use App\Services\AdminAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $request, AdminAnalyticsService $analyticsService): View
    {
        $audience = EventAudience::tryFrom((string) $request->query('audience', ''));

        return view('admin.analytics', [
            'charts' => $analyticsService->chartPayload($audience),
            'audience' => $audience,
        ]);
    }
}
