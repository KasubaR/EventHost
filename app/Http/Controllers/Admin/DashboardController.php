<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'stats' => [
                'users' => User::query()->count(),
                'events' => Event::query()->count(),
                'published_events' => Event::query()->where('is_published', true)->count(),
                'rsvps' => Rsvp::query()->count(),
                'pending_reports' => Report::query()->where('status', Report::STATUS_PENDING)->count(),
                'failed_notifications' => NotificationLog::query()->where('status', NotificationLog::STATUS_FAILED)->count(),
            ],
            'recentUsers' => User::query()->latest()->limit(5)->get(),
            'recentEvents' => Event::query()->with('user:id,name,email')->latest()->limit(5)->get(),
            'recentRsvps' => Rsvp::query()->with(['guest:id,name', 'event:id,name'])->latest()->limit(5)->get(),
            'recentFailedNotifications' => NotificationLog::query()
                ->with(['event:id,name', 'guest:id,name'])
                ->where('status', NotificationLog::STATUS_FAILED)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
