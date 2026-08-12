<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\BillingPlan;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $method = $request->query('method');
        $stuckOnly = $request->query('stuck') === '1';
        $stuckHours = (int) config('services.lenco.stuck_payment_hours', 2);

        $query = Payment::query()
            ->with(['user:id,name,email'])
            ->when(is_string($status) && $status !== '', fn ($q) => $q->where('status', $status))
            ->when(is_string($method) && $method !== '', fn ($q) => $q->where('payment_method', $method))
            ->when($stuckOnly, fn ($q) => $q->stuck($stuckHours))
            ->orderByDesc('created_at');

        $payments = $query->paginate(40)->withQueryString();

        $counts = [
            'pending' => Payment::query()->whereIn('status', ['pending', 'processing'])->count(),
            'completed' => Payment::query()->where('status', 'completed')->count(),
            'failed' => Payment::query()->where('status', 'failed')->count(),
            'cancelled' => Payment::query()->where('status', 'cancelled')->count(),
            'stuck' => Payment::query()->stuck($stuckHours)->count(),
        ];

        return view('admin.payments.index', [
            'payments' => $payments,
            'counts' => $counts,
            'filterStatus' => is_string($status) ? $status : '',
            'filterMethod' => is_string($method) ? $method : '',
            'stuckOnly' => $stuckOnly,
            'stuckHours' => $stuckHours,
            'plans' => BillingPlan::all(),
        ]);
    }

    public function show(Payment $payment): View
    {
        $payment->load(['user:id,name,email,phone']);

        return view('admin.payments.show', [
            'payment' => $payment,
            'plan' => BillingPlan::get($payment->plan_key),
            'stuckHours' => (int) config('services.lenco.stuck_payment_hours', 2),
        ]);
    }
}
