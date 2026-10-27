<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/datetime-picker.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/datetime-picker.js') }}" defer></script>
    @endpush

    <x-slot name="title">Revenue | {{ $adminEvent->name }}</x-slot>

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
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i>
            @if ((float) session('payout_remaining', 0) > 0)
                Part payment recorded. {{ \App\Support\TicketingSettings::formatZmw(session('payout_remaining')) }} is still pending.
            @else
                Payout recorded. Paid in full.
            @endif
        </div>
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
            {{-- Scrolls sideways when the columns do not fit; the min-width keeps dates and times on one line. --}}
            <div class="admin-table-wrap">
            <table class="admin-table" style="min-width: 980px; white-space: nowrap;">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Buyer</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Gross</th>
                        <th>Platform fee</th>
                        <th>Host amount</th>
                        <th>Method</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td>{{ $order->order_reference }}</td>
                            <td>{{ $order->buyer_name }}<br><span class="admin-muted">{{ $order->buyer_email }}</span></td>
                            <td>{{ $order->paid_at?->format('j M Y') ?? '-' }}</td>
                            <td>{{ $order->paid_at?->format('H:i') ?? '-' }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($order->face_value) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($order->commission_amount) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($order->host_amount) }}</td>
                            @php
                                $pay = $order->payment;
                                // The mobile-money operator (who took the money), not the internal method name.
                                $operator = match (true) {
                                    $pay === null => null,
                                    $pay->payment_method === 'card' => 'Card',
                                    $pay->payment_method === 'bank_transfer' => 'Bank transfer',
                                    filled($pay->provider) => strtoupper($pay->provider) === 'MTN' ? 'MTN' : \Illuminate\Support\Str::headline($pay->provider),
                                    default => \Illuminate\Support\Str::headline((string) $pay->payment_method),
                                };
                            @endphp
                            <td>{{ $operator ?? '-' }}</td>
                            <td>
                                @if ($pay)
                                    <span class="tkt-sales-status {{ $pay->status === 'completed' ? 'tkt-sales-status--on' : 'tkt-sales-status--off' }}">{{ \Illuminate\Support\Str::headline($pay->status) }}</span>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
            <div class="evt-pagination">{{ $orders->links() }}</div>
        @endif
    </div>

    <div class="admin-panel-card admin-mt-lg">
        <h2>Payouts
            <span class="admin-muted">&middot;
                {{ ['none' => 'Not paid yet', 'partial' => 'Partly paid', 'paid' => 'Paid in full'][$payoutState] }}</span>
        </h2>
        @if ($payouts->isEmpty())
            <p class="admin-muted">No payouts recorded yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Note</th>
                        <th>Proof</th>
                        <th>Recorded by</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payouts as $payout)
                        <tr>
                            <td>{{ $payout->paid_on->format('j M Y') }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($payout->amount) }}</td>
                            <td>{{ $payout->note ?? '-' }}</td>
                            <td>
                                @if ($payout->hasProof())
                                    <a href="{{ route('admin.ticketing.revenue.payouts.proof', [$adminEvent, $payout]) }}" target="_blank" rel="noopener">View</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $payout->paidBy?->name ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (auth('admin')->user()?->can('ticketing.payouts.manage') && $pendingPayable > 0)
            <h3 class="admin-mt-lg">Record a payout</h3>
            <form method="post" action="{{ route('admin.ticketing.revenue.payouts.store', $adminEvent) }}" class="profile-fields" enctype="multipart/form-data">
                @csrf
                @php $paymentType = old('payment_type', 'full'); @endphp
                <div class="profile-field">
                    <span class="profile-label">What are you paying?</span>
                    <div class="evt-product-choice">
                        <label class="evt-product-choice-card">
                            <input type="radio" name="payment_type" value="full" class="evt-check-input" @checked($paymentType === 'full')>
                            <span>
                                <strong>Pay in full</strong>
                                <span class="evt-product-choice-hint">{{ \App\Support\TicketingSettings::formatZmw($pendingPayable) }}, everything pending.</span>
                            </span>
                        </label>
                        <label class="evt-product-choice-card">
                            <input type="radio" name="payment_type" value="part" class="evt-check-input" @checked($paymentType === 'part')>
                            <span>
                                <strong>Part payment</strong>
                                <span class="evt-product-choice-hint">Pay some now; the rest stays pending.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="profile-field" id="payout_amount_field">
                    <label for="payout_amount" class="profile-label">Amount <span class="admin-muted">(part payment only)</span></label>
                    <input id="payout_amount" type="number" name="amount" step="0.01" min="0.01" max="{{ $pendingPayable }}"
                           value="{{ old('amount') }}"
                           class="profile-input {{ $errors->has('amount') ? 'profile-input--error' : '' }}">
                    <p class="admin-muted">Less than the current pending payout of {{ \App\Support\TicketingSettings::formatZmw($pendingPayable) }}; the rest stays pending.</p>
                </div>
                <div class="profile-field">
                    <label for="payout_paid_on" class="profile-label">Date</label>
                    <input id="payout_paid_on" type="date" name="paid_on" data-dtp value="{{ old('paid_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                           class="profile-input {{ $errors->has('paid_on') ? 'profile-input--error' : '' }}" required>
                </div>
                <div class="profile-field">
                    <label for="payout_note" class="profile-label">Note <span class="admin-muted">(optional)</span></label>
                    <input id="payout_note" type="text" name="note" maxlength="500" value="{{ old('note') }}" placeholder="e.g. bank transfer ref"
                           class="profile-input {{ $errors->has('note') ? 'profile-input--error' : '' }}">
                </div>
                <div class="profile-field">
                    <label for="payout_proof" class="profile-label">Proof of payment <span class="admin-muted">(optional)</span></label>
                    <input id="payout_proof" type="file" name="proof" accept=".jpg,.jpeg,.png,.webp,.pdf"
                           class="profile-input evt-design-media-file {{ $errors->has('proof') ? 'profile-input--error' : '' }}">
                    <p class="admin-muted">A receipt photo or PDF, up to 5 MB. Kept privately; only you and the event's host can open it.</p>
                </div>
                <div class="profile-actions">
                    <button type="submit" class="btn-primary">Record payout</button>
                </div>
            </form>
            <script>
                (function () {
                    var radios = document.querySelectorAll('input[name="payment_type"]');
                    var field = document.getElementById('payout_amount_field');
                    function sync() {
                        var part = document.querySelector('input[name="payment_type"]:checked');
                        field.style.display = part && part.value === 'part' ? '' : 'none';
                    }
                    for (var i = 0; i < radios.length; i++) { radios[i].addEventListener('change', sync); }
                    sync();
                })();
            </script>
        @endif
    </div>
</x-admin-layout>
