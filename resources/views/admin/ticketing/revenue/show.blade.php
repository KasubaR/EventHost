<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Revenue — {{ $adminEvent->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">{{ $adminEvent->name }}</h1>
                <p class="dph-sub">Ticketing revenue</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('admin.ticketing.revenue.index') }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> All events</a>
                <a href="{{ route('admin.ticketing.show', $adminEvent) }}" class="evt-btn-outline"><i class="fa-solid fa-clipboard-check"></i> Activation</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'payout-recorded')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Payout recorded.</div>
    @endif
    @if ($errors->any())
        <div class="evt-flash admin-tpl-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="evt-grid-2 evt-rsvp-summary-grid admin-mt-lg">
        <div class="evt-stat-card">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($grossSales) }}</div>
            <div class="evt-stat-label">Gross sales</div>
        </div>
        <div class="evt-stat-card">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($platformFees) }}</div>
            <div class="evt-stat-label">Platform revenue</div>
        </div>
        <div class="evt-stat-card evt-stat-card--accent">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($pendingPayable) }}</div>
            <div class="evt-stat-label">Pending host payout</div>
        </div>
        <div class="evt-stat-card">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($paidOut) }}</div>
            <div class="evt-stat-label">Paid out so far</div>
        </div>
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>Orders</h2>
        @if ($orders->isEmpty())
            <p class="admin-muted">No paid orders yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Buyer</th>
                        <th>Paid</th>
                        <th>Gross</th>
                        <th>Platform fee</th>
                        <th>Host amount</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td>{{ $order->order_reference }}</td>
                            <td>{{ $order->buyer_name }}<br><span class="admin-muted">{{ $order->buyer_email }}</span></td>
                            <td>{{ $order->paid_at?->format('j M Y H:i') ?? '—' }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($order->face_value) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($order->commission_amount) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($order->host_amount) }}</td>
                            <td>{{ $order->payment?->payment_method ?? '—' }} · {{ $order->payment?->status ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="evt-pagination">{{ $orders->links() }}</div>
        @endif
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>Payouts</h2>
        @if ($payouts->isEmpty())
            <p class="admin-muted">No payouts recorded yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Note</th>
                        <th>Recorded by</th>
                    </tr>
                </thead>
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

        @if (auth('admin')->user()?->can('ticketing.payouts.manage') && $pendingPayable > 0)
            <h3 class="admin-mt-lg">Record a payout</h3>
            <form method="post" action="{{ route('admin.ticketing.revenue.payouts.store', $adminEvent) }}" class="profile-fields">
                @csrf
                <div class="profile-field">
                    <label for="payout_amount" class="profile-label">Amount</label>
                    <input id="payout_amount" type="number" name="amount" step="0.01" min="0.01" max="{{ $pendingPayable }}"
                           value="{{ old('amount', number_format($pendingPayable, 2, '.', '')) }}"
                           class="profile-input {{ $errors->has('amount') ? 'profile-input--error' : '' }}" required>
                    <p class="admin-muted">Up to the current pending payout of {{ \App\Support\TicketingSettings::formatZmw($pendingPayable) }}.</p>
                </div>
                <div class="profile-field">
                    <label for="payout_paid_on" class="profile-label">Date</label>
                    <input id="payout_paid_on" type="date" name="paid_on" value="{{ old('paid_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                           class="profile-input {{ $errors->has('paid_on') ? 'profile-input--error' : '' }}" required>
                </div>
                <div class="profile-field">
                    <label for="payout_note" class="profile-label">Note <span class="admin-muted">(optional)</span></label>
                    <input id="payout_note" type="text" name="note" maxlength="500" value="{{ old('note') }}" placeholder="e.g. bank transfer ref"
                           class="profile-input {{ $errors->has('note') ? 'profile-input--error' : '' }}">
                </div>
                <div class="profile-actions">
                    <button type="submit" class="btn-primary">Record payout</button>
                </div>
            </form>
        @endif
    </div>
</x-admin-layout>
