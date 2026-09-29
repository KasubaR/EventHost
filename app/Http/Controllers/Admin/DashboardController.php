<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketingStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Report;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\BillingPlan;
use Illuminate\Support\Collection;
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
            'pendingTicketingRequests' => $this->pendingTicketingRequests(),
            'recentFailedNotifications' => NotificationLog::query()
                ->with(['event:id,name', 'guest:id,name'])
                ->where('status', NotificationLog::STATUS_FAILED)
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Flat rate used only for the admin dashboard's estimated-tax card — not a
     * withholding or remittance calculation, just revenue_total x this rate.
     */
    private const TAX_RATE = 0.04;

    /**
     * Revenue is summed only over the configured billing currency so mixed-currency
     * rows can never be added together. Returns null when the admin may not see
     * payment data, and the view then omits the finance cards entirely.
     *
     * @return array{revenue_total: float, revenue_month: float, pending_payments: int, completed_payments: int, estimated_tax: float}|null
     */
    private function financeStats(): ?array
    {
        if (auth('admin')->user()?->can('payments.view') !== true) {
            return null;
        }

        $completed = fn () => Payment::query()
            ->where('status', 'completed')
            ->where('currency', BillingPlan::currency());

        $revenueTotal = (float) $completed()->sum('amount');

        return [
            'revenue_total' => $revenueTotal,
            'revenue_month' => (float) $completed()
                ->where('completed_at', '>=', now()->startOfMonth())
                ->sum('amount'),
            'completed_payments' => $completed()->count(),
            'pending_payments' => Payment::query()->inProgress()->count(),
            'estimated_tax' => $revenueTotal * self::TAX_RATE,
        ];
    }

    /**
     * Returns null when the admin may not review ticketing, and the view then
     * omits the panel entirely — same convention as financeStats().
     *
     * @return Collection<int, Event>|null
     */
    private function pendingTicketingRequests(): ?Collection
    {
        if (auth('admin')->user()?->can('ticketing.view') !== true) {
            return null;
        }

        return Event::query()
            ->ticketed()
            ->where('ticketing_status', TicketingStatus::PendingReview)
            ->with('user:id,name,email')
            ->withCount('ticketTypes')
            ->orderByDesc('ticketing_submitted_at')
            ->limit(5)
            ->get();
    }
}
