@php
    $payment = $order->payment;
@endphp
<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Trace {{ $order->order_reference }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">{{ $order->order_reference }}</h1>
                <p class="dph-sub">Lenco payment → order → tickets → ledger → payouts, for this one order.</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('admin.ticketing.reconciliation.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Reconciliation</a>
                @if ($order->event)
                    <a href="{{ route('admin.ticketing.revenue.show', $order->event) }}" class="evt-btn-outline"><i class="fa-solid fa-sack-dollar"></i> Event revenue</a>
                @endif
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'reverified')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Re-verified with Lenco — status below is current.</div>
    @endif
    @if ($errors->has('reverify'))
        <div class="evt-flash admin-tpl-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first('reverify') }}</div>
    @endif

    <div class="admin-panel-card admin-mt-lg">
        <h2>1. Lenco payment</h2>
        @if ($payment === null)
            <p class="admin-muted">No TicketPayment record exists for this order — checkout never reached the payment step.</p>
        @else
            <table class="admin-table">
                <tbody>
                    <tr><td>Reference</td><td>{{ $payment->payment_reference }}</td></tr>
                    <tr><td>Method</td><td>{{ $payment->payment_method }} @if ($payment->provider) ({{ $payment->provider }}) @endif</td></tr>
                    <tr><td>Amount</td><td>{{ \App\Support\TicketingSettings::formatZmw($payment->amount) }} {{ $payment->currency }}</td></tr>
                    <tr><td>Status</td><td>{{ $payment->status }} @if ($payment->lenco_status) (Lenco: {{ $payment->lenco_status }}) @endif</td></tr>
                    <tr><td>Lenco transaction ID</td><td>{{ $payment->lenco_transaction_id ?? '—' }}</td></tr>
                    <tr><td>Lenco reference</td><td>{{ $payment->lenco_reference ?? '—' }}</td></tr>
                    <tr><td>Webhook received</td><td>{{ $payment->webhook_received ? 'Yes, '.$payment->webhook_received_at?->format('j M Y H:i') : 'No — status came from a poll/verify call, not a webhook' }}</td></tr>
                    <tr><td>Created</td><td>{{ $payment->created_at?->format('j M Y H:i') }} ({{ $payment->created_at?->diffForHumans() }})</td></tr>
                    @if ($payment->completed_at)
                        <tr><td>Completed</td><td>{{ $payment->completed_at->format('j M Y H:i') }}</td></tr>
                    @endif
                    @if ($payment->failure_reason)
                        <tr><td>Failure reason</td><td>{{ $payment->failure_reason }}</td></tr>
                    @endif
                </tbody>
            </table>

            @if ($payment->lenco_response)
                <details class="admin-mt-lg">
                    <summary>Raw Lenco response</summary>
                    <pre style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($payment->lenco_response, JSON_PRETTY_PRINT) }}</pre>
                </details>
            @endif

            @if (auth('admin')->user()?->can('ticketing.reconcile') && in_array($payment->status, ['pending', 'processing', 'failed', 'cancelled'], true))
                <form method="post" action="{{ route('admin.ticketing.reconciliation.reverify', $order) }}" class="admin-mt-lg">
                    @csrf
                    <button type="submit" class="btn-primary">Re-verify with Lenco</button>
                    <p class="admin-muted">Asks Lenco for this payment's current status right now and applies it — same check the buyer's own "check status" button runs.</p>
                </form>
            @endif
        @endif
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>2. Order</h2>
        <table class="admin-table">
            <tbody>
                <tr><td>Event</td><td>{{ $order->event->name ?? 'Deleted event' }}</td></tr>
                <tr><td>Buyer</td><td>{{ $order->buyer_name }} · {{ $order->buyer_email }} · {{ $order->buyer_phone }}</td></tr>
                <tr><td>Status</td><td>{{ $order->status->label() }}</td></tr>
                <tr><td>Face value</td><td>{{ \App\Support\TicketingSettings::formatZmw($order->face_value) }}</td></tr>
                <tr><td>Commission ({{ $order->commission_percent }}%, {{ $order->commission_mode?->value }})</td><td>{{ \App\Support\TicketingSettings::formatZmw($order->commission_amount) }}</td></tr>
                <tr><td>Buyer fee</td><td>{{ \App\Support\TicketingSettings::formatZmw($order->buyer_fee) }}</td></tr>
                <tr><td>Buyer total paid</td><td><strong>{{ \App\Support\TicketingSettings::formatZmw($order->buyer_total) }}</strong></td></tr>
                <tr><td>Host amount</td><td>{{ \App\Support\TicketingSettings::formatZmw($order->host_amount) }}</td></tr>
                <tr><td>Paid at</td><td>{{ $order->paid_at?->format('j M Y H:i') ?? '—' }}</td></tr>
            </tbody>
        </table>

        <h3 class="admin-mt-lg">Items</h3>
        <table class="admin-table">
            <thead><tr><th>Ticket type</th><th>Unit price</th><th>Qty</th><th>Subtotal</th></tr></thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->ticket_type_name }}</td>
                        <td>{{ \App\Support\TicketingSettings::formatZmw($item->unit_price) }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ \App\Support\TicketingSettings::formatZmw($item->subtotal) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>3. Tickets issued ({{ $order->tickets->count() }})</h2>
        @if ($order->tickets->isEmpty())
            <p class="admin-muted">No tickets issued for this order yet.</p>
        @else
            <table class="admin-table">
                <thead><tr><th>Attendee</th><th>Status</th><th>Checked in</th></tr></thead>
                <tbody>
                    @foreach ($order->tickets as $ticket)
                        <tr>
                            <td>{{ $ticket->attendee_name }}</td>
                            <td>{{ $ticket->status->value }}</td>
                            <td>{{ $ticket->checked_in_at?->format('j M Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>4. Revenue ledger entries for this order</h2>
        @if ($order->revenueEntries->isEmpty())
            <p class="admin-muted">No ledger entries for this order.</p>
        @else
            <table class="admin-table">
                <thead><tr><th>Type</th><th>Gross</th><th>Platform fee</th><th>Host amount</th><th>Balance after</th><th>When</th></tr></thead>
                <tbody>
                    @foreach ($order->revenueEntries as $entry)
                        <tr>
                            <td>{{ $entry->typeLabel() }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($entry->gross_amount) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($entry->platform_fee) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($entry->host_amount) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($entry->balance_after) }}</td>
                            <td>{{ $entry->created_at?->format('j M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($order->event)
        <div class="admin-panel-card admin-mt-lg">
            <h2>5. Event balance &amp; payouts</h2>
            <p>Current pending payable for {{ $order->event->name }}: <strong>{{ \App\Support\TicketingSettings::formatZmw($balance ?? 0) }}</strong></p>
            @if ($payouts->isEmpty())
                <p class="admin-muted">No payouts recorded for this event yet.</p>
            @else
                <table class="admin-table">
                    <thead><tr><th>Date</th><th>Amount</th><th>Note</th><th>Recorded by</th></tr></thead>
                    <tbody>
                        @foreach ($payouts as $payout)
                            <tr>
                                <td>{{ $payout->paid_on->format('j M Y') }}</td>
                                <td>{{ \App\Support\TicketingSettings::formatZmw($payout->amount) }}</td>
                                <td>{{ $payout->note ?? '—' }}</td>
                                <td>{{ $payout->paidBy?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</x-admin-layout>
