<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
    @endpush

    <x-slot name="title">Payments</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Payments</h1>
                <p class="dph-sub">Lenco gateway transactions and stalled payment monitoring.</p>
            </div>
        </div>
    </x-slot>

    <div class="dash-stats dash-stats--six admin-stat-grid admin-mt-md">
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--orange"><i class="fa-solid fa-clock" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['pending']) }}</div>
                <div class="dsc-label">In progress</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--green"><i class="fa-solid fa-check" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['completed']) }}</div>
                <div class="dsc-label">Completed</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--accent"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['failed']) }}</div>
                <div class="dsc-label">Failed</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--purple"><i class="fa-solid fa-ban" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['cancelled']) }}</div>
                <div class="dsc-label">Cancelled</div>
            </div>
        </div>
        <div class="dash-stat-card">
            <div class="dsc-icon dsc-icon--teal"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></div>
            <div class="dsc-body">
                <div class="dsc-value">{{ number_format($counts['stuck']) }}</div>
                <div class="dsc-label">Stuck &gt; {{ $stuckHours }}h</div>
            </div>
        </div>
    </div>

    <form method="get" action="{{ route('admin.payments.index') }}" class="admin-filter-bar admin-mt-md">
        <div>
            <label class="evt-sr-only" for="admin-payment-status">Status</label>
            <select id="admin-payment-status" name="status">
                <option value="">Any status</option>
                <option value="pending" @selected($filterStatus === 'pending')>Pending</option>
                <option value="processing" @selected($filterStatus === 'processing')>Processing</option>
                <option value="completed" @selected($filterStatus === 'completed')>Completed</option>
                <option value="failed" @selected($filterStatus === 'failed')>Failed</option>
                <option value="cancelled" @selected($filterStatus === 'cancelled')>Cancelled</option>
            </select>
        </div>
        <div>
            <label class="evt-sr-only" for="admin-payment-method">Method</label>
            <select id="admin-payment-method" name="method">
                <option value="">Any method</option>
                <option value="mobile_money" @selected($filterMethod === 'mobile_money')>Mobile money</option>
                <option value="bank_transfer" @selected($filterMethod === 'bank_transfer')>Bank transfer</option>
            </select>
        </div>
        <label class="admin-checkbox-inline">
            <input type="checkbox" name="stuck" value="1" @checked($stuckOnly)> Stuck only
        </label>
        <button type="submit" class="btn-primary">Filter</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>User</th>
                <th>Plan</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Status</th>
                <th>Reference</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td>{{ $payment->user?->name ?? '—' }}</td>
                    <td>{{ $plans[$payment->plan_key]['label'] ?? $payment->plan_key }}</td>
                    <td>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                    <td>{{ str_replace('_', ' ', $payment->payment_method) }}</td>
                    <td>
                        {{ ucfirst($payment->status) }}
                        @if ($payment->isStuck($stuckHours))
                            <span class="evt-credit-badge">Stuck</span>
                        @endif
                    </td>
                    <td><code>{{ $payment->payment_reference }}</code></td>
                    <td>{{ $payment->created_at?->format('M j, Y H:i') }}</td>
                    <td><a href="{{ route('admin.payments.show', $payment) }}">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8">No payments found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $payments->links() }}
</x-admin-layout>
