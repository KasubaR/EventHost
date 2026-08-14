<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Report;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\BillingPlan;
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
            'finance' => $this->financeStats(),
            'currency' => BillingPlan::currency(),
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

    /**
     * Revenue is summed only over the configured billing currency so mixed-currency
     * rows can never be added together. Returns null when the admin may not see
     * payment data, and the view then omits the finance cards entirely.
     *
     * @return array{revenue_total: float, revenue_month: float, pending_payments: int, completed_payments: int}|null
     */
    private function financeStats(): ?array
    {
        if (auth('admin')->user()?->can('payments.view') !== true) {
            return null;
        }

        $completed = fn () => Payment::query()
            ->where('status', 'completed')
            ->where('currency', BillingPlan::currency());

        return [
            'revenue_total' => (float) $completed()->sum('amount'),
            'revenue_month' => (float) $completed()
                ->where('completed_at', '>=', now()->startOfMonth())
                ->sum('amount'),
            'completed_payments' => $completed()->count(),
            'pending_payments' => Payment::query()->inProgress()->count(),
        ];
    }
}
