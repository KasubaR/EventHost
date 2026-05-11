<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAnalyticsService;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(AdminAnalyticsService $analyticsService): View
    {
        return view('admin.analytics', [
            'charts' => $analyticsService->chartPayload(),
        ]);
    }
}
