<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketOrder;
use App\Services\LencoService;
use App\Services\TicketPaymentStatusService;
use App\Services\TicketReconciliationService;
use App\Support\AdminActivity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Phase 24 — read-only reconciliation dashboard plus one manual mutation
 * (re-verify a stuck payment with Lenco, reusing the exact verify pattern
 * EventTicketCheckoutController::verify() already uses for buyers). Kept
 * separate from Admin\TicketRevenueController (Phase 23, per-event money
 * reporting + payouts) — this controller audits across every event, and
 * answers "where did this payment go" without needing the event first.
 */
class ReconciliationController extends Controller
{
    public function index(Request $request, TicketReconciliationService $reconciliation): View
    {
        $q = trim((string) $request->query('q', ''));

        return view('admin.ticketing.reconciliation.index', [
            'q' => $q,
            'searchResults' => $q !== '' ? $reconciliation->search($q) : null,
            'checks' => $reconciliation->runAll(),
        ]);
    }

    public function order(TicketOrder $order, TicketReconciliationService $reconciliation): View
    {
        return view('admin.ticketing.reconciliation.order', $reconciliation->traceFor($order));
    }

    public function reverify(
        TicketOrder $order,
        LencoService $lenco,
        TicketPaymentStatusService $statusService,
    ): RedirectResponse {
        $payment = $order->payment;
        abort_if($payment === null, 404, 'This order has no payment record to re-verify.');

        try {
            $verification = $lenco->verifyByReference($order->order_reference);
            $statusService->applyVerificationResult($payment, $verification);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.ticketing.reconciliation.order', $order)
                ->withErrors(['reverify' => 'Lenco verification failed: '.$e->getMessage()]);
        }

        AdminActivity::log('Admin re-verified a ticket payment with Lenco', [
            'ticket_order_id' => $order->id,
            'ticket_payment_id' => $payment->id,
        ]);

        return redirect()
            ->route('admin.ticketing.reconciliation.order', $order)
            ->with('status', 'reverified');
    }
}
