<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ContributionPayoutExceedsBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreContributionPayoutRequest;
use App\Models\Admin;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Services\ContributionPayoutService;
use App\Services\ContributionRevenueAnalyticsService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Platform-wide contribution revenue (Phase 2 of plans/contributions.md) —
 * kept separate from Admin\EventContributionController, which owns the
 * enable/amount toggle. This controller owns money: the summary dashboard,
 * the per-event drill-down, and recording a manual payout. Twin of
 * Admin\TicketRevenueController.
 */
class ContributionRevenueController extends Controller
{
    public function index(ContributionRevenueAnalyticsService $analytics): View
    {
        return view('admin.contributions.revenue.index', [
            'summary' => $analytics->platformSummary(),
            'rows' => $analytics->perEventBreakdown(),
        ]);
    }

    public function show(Event $event, ContributionRevenueAnalyticsService $analytics): View
    {
        abort_unless($event->isInvitation(), 404);

        return view('admin.contributions.revenue.show', [
            'adminEvent' => $event,
            'collected' => $analytics->collectedFor($event),
            'pendingPayable' => $analytics->balanceFor($event),
            'payments' => $analytics->paymentsFor($event),
            'payouts' => $analytics->payoutsFor($event),
        ]);
    }

    /**
     * Completed payments for one event, newest first — same shape as
     * EventTicketManagementController::export(), the host-facing tickets
     * CSV, but on the admin side and scoped to this table's columns.
     */
    public function export(Event $event): StreamedResponse
    {
        abort_unless($event->isInvitation(), 404);

        $paymentsQuery = ContributionPayment::query()
            ->whereHas('contribution', fn ($q) => $q->where('event_id', $event->id))
            ->where('status', 'completed')
            ->with('contribution:id,contributor_name,contributor_phone,contributor_email')
            ->orderByDesc('completed_at');

        $filename = 'contributions-'.str($event->name)->slug().'.csv';

        return response()->streamDownload(function () use ($paymentsQuery) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date',
                'Contributor',
                'Phone',
                'Email',
                'Amount',
                'Method',
                'Reference',
            ]);

            $paymentsQuery->chunk(200, function ($chunk) use ($handle) {
                foreach ($chunk as $payment) {
                    $contribution = $payment->contribution;

                    fputcsv($handle, [
                        $payment->completed_at?->format('Y-m-d H:i') ?? '',
                        $contribution?->contributor_name ?? '',
                        $contribution?->contributor_phone ?? '',
                        $contribution?->contributor_email ?? '',
                        number_format((float) $payment->amount, 2, '.', ''),
                        $payment->payment_method,
                        $payment->payment_reference,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function storePayout(
        StoreContributionPayoutRequest $request,
        Event $event,
        ContributionPayoutService $payouts,
    ): RedirectResponse {
        abort_unless($event->isInvitation(), 404);

        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            abort(403);
        }

        $validated = $request->validated();

        try {
            $payout = $payouts->recordPayout(
                $event,
                $admin,
                (float) $validated['amount'],
                $validated['note'] ?? null,
                Carbon::parse($validated['paid_on']),
            );
        } catch (ContributionPayoutExceedsBalanceException $e) {
            return redirect()
                ->route('admin.contributions.revenue.show', $event)
                ->withErrors(['amount' => $e->getMessage()]);
        }

        AdminActivity::log('Admin recorded a contribution payout', [
            'event_id' => $event->id,
            'contribution_payout_id' => $payout->id,
            'amount' => (string) $payout->amount,
        ]);

        return redirect()
            ->route('admin.contributions.revenue.show', $event)
            ->with('status', 'payout-recorded');
    }
}
