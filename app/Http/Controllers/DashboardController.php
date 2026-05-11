<?php

namespace App\Http\Controllers;

use App\Services\DashboardAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analyticsService): View
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'analytics' => $analyticsService->forUser($user),
        ]);
    }
}
