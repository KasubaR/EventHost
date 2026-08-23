<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Ticketing Reconciliation</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Ticketing Reconciliation</h1>
                <p class="dph-sub">Prove the chain balances — Lenco payment → order → tickets → ledger → payouts — and trace any single payment.</p>
            </div>
        </div>
    </x-slot>

    @include('admin.ticketing.partials.nav', ['active' => 'reconciliation'])

    <div class="admin-panel-card admin-mt-lg">
        <h2>Find a payment</h2>
        <p class="admin-muted">Order reference, payment reference, Lenco transaction ID, buyer email/phone, or an amount (e.g. "500").</p>
        <form method="get" action="{{ route('admin.ticketing.reconciliation.index') }}" class="admin-filter-row">
            <input type="text" name="q" value="{{ $q }}" placeholder="Where did this K500 go?" class="profile-input" style="max-width: 360px;">
            <button type="submit" class="btn-primary">Search</button>
        </form>

        @if ($searchResults !== null)
            @if ($searchResults->isEmpty())
                <p class="admin-muted admin-mt-lg">No matching order found for "{{ $q }}".</p>
            @else
                <table class="admin-table admin-mt-lg">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Event</th>
                            <th>Buyer</th>
                            <th>Status</th>
                            <th>Total paid</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($searchResults as $order)
                            <tr>
                                <td>{{ $order->order_reference }}</td>
                                <td>{{ $order->event->name ?? '—' }}</td>
                                <td>{{ $order->buyer_name }}<br><span class="admin-muted">{{ $order->buyer_email }}</span></td>
                                <td>{{ $order->status->label() }}</td>
                                <td>{{ \App\Support\TicketingSettings::formatZmw($order->buyer_total) }}</td>
                                <td><a href="{{ route('admin.ticketing.reconciliation.order', $order) }}" class="evt-btn-outline evt-btn-tiny">Trace</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>System health checks</h2>
        <p class="admin-muted">Every check should read zero. A count above zero needs a look — click a row to open its full trace.</p>

        @foreach ($checks as $check)
            <div class="admin-mt-lg">
                <div class="admin-filter-row" style="justify-content: space-between; align-items: center;">
                    <div>
                        <strong>{{ $check['label'] }}</strong>
                        <p class="admin-muted">{{ $check['description'] }}</p>
                    </div>
                    @if (count($check['rows']) === 0)
                        <span class="evt-pill evt-pill--accepted">OK</span>
                    @else
                        <span class="evt-pill evt-pill--declined">{{ count($check['rows']) }} {{ Str::plural('issue', count($check['rows'])) }}</span>
                    @endif
                </div>

                @if (count($check['rows']) > 0)
                    <table class="admin-table">
                        <tbody>
                            @foreach ($check['rows'] as $row)
                                <tr>
                                    <td>{{ $row['title'] }}</td>
                                    <td class="admin-muted">{{ $row['subtitle'] }}</td>
                                    <td>
                                        @if ($row['order'] !== null)
                                            <a href="{{ route('admin.ticketing.reconciliation.order', $row['order']) }}" class="evt-btn-outline evt-btn-tiny">Trace</a>
                                        @elseif ($check['key'] === 'negative_balance' && ($row['event'] ?? null) !== null)
                                            <a href="{{ route('admin.ticketing.revenue.show', $row['event']) }}" class="evt-btn-outline evt-btn-tiny">View event</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach
    </div>
</x-admin-layout>
