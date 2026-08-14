<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Payment {{ $payment->payment_reference }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Payment detail</h1>
                <p class="dph-sub"><code>{{ $payment->payment_reference }}</code></p>
            </div>
            <a href="{{ route('admin.payments.index') }}" class="btn-outline">Back to payments</a>
        </div>
    </x-slot>

    @if ($payment->isStuck($stuckHours))
        <div class="evt-flash evt-flash--warn admin-mt-sm">
            <i class="fa-solid fa-hourglass-half"></i>
            This payment has been in progress for more than {{ $stuckHours }} hour(s).
        </div>
    @endif

    <div class="admin-detail-grid admin-mt-md">
        <div class="profile-header-card">
            <h2 class="admin-detail-heading">Summary</h2>
            <dl class="admin-dl">
                <dt>Status</dt><dd>{{ ucfirst($payment->status) }}</dd>
                <dt>Plan</dt><dd>{{ is_array($plan) ? ($plan['label'] ?? $payment->plan_key) : $payment->plan_key }}</dd>
                <dt>Amount</dt><dd>{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</dd>
                <dt>Credits granted</dt><dd>{{ $payment->credits_granted }}</dd>
                <dt>Method</dt><dd>{{ str_replace('_', ' ', $payment->payment_method) }} @if($payment->provider)({{ strtoupper($payment->provider) }})@endif</dd>
                <dt>User</dt>
                <dd>
                    @if ($payment->user)
                        <a href="{{ route('admin.users.show', $payment->user) }}" class="admin-link">{{ $payment->user->name }}</a>
                        <span class="admin-muted">({{ $payment->user->email }})</span>
                    @else
                        —
                    @endif
                </dd>
            </dl>
        </div>

        <div class="profile-header-card">
            <h2 class="admin-detail-heading">Lenco</h2>
            <dl class="admin-dl">
                <dt>Transaction ID</dt><dd><code>{{ $payment->lenco_transaction_id ?? '—' }}</code></dd>
                <dt>Lenco reference</dt><dd><code>{{ $payment->lenco_reference ?? '—' }}</code></dd>
                <dt>Lenco status</dt><dd>{{ $payment->lenco_status ?? '—' }}</dd>
                <dt>Webhook received</dt><dd>{{ $payment->webhook_received ? 'Yes' : 'No' }}</dd>
                @if ($payment->webhook_received_at)
                    <dt>Webhook at</dt><dd>{{ $payment->webhook_received_at->format('M j, Y H:i:s') }}</dd>
                @endif
                @if ($payment->payment_instructions)
                    <dt>Instructions</dt><dd>{{ $payment->payment_instructions }}</dd>
                @endif
                @if ($payment->failure_reason)
                    <dt>Failure reason</dt><dd>{{ $payment->failure_reason }}</dd>
                @endif
            </dl>
        </div>

        <div class="profile-header-card">
            <h2 class="admin-detail-heading">Timeline</h2>
            <dl class="admin-dl">
                <dt>Created</dt><dd>{{ $payment->created_at?->format('M j, Y H:i:s') ?? '—' }}</dd>
                <dt>Completed</dt><dd>{{ $payment->completed_at?->format('M j, Y H:i:s') ?? '—' }}</dd>
                <dt>Failed</dt><dd>{{ $payment->failed_at?->format('M j, Y H:i:s') ?? '—' }}</dd>
                <dt>Cancelled</dt><dd>{{ $payment->cancelled_at?->format('M j, Y H:i:s') ?? '—' }}</dd>
                <dt>Fulfilled (notified)</dt><dd>{{ $payment->notified_at?->format('M j, Y H:i:s') ?? '—' }}</dd>
                <dt>Expires</dt><dd>{{ $payment->expires_at?->format('M j, Y H:i:s') ?? '—' }}</dd>
            </dl>
        </div>
    </div>
</x-admin-layout>
