<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Payouts — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Ticketing</h1>
                <p class="dph-sub">{{ $event->name }} · {{ $event->ticketing_status->label() }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'payouts'])

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card evt-stat-card--accent">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($pendingPayable) }}</div>
                <div class="evt-stat-label">Pending payout</div>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Payout history</h2>
                <p>Payouts are recorded by EventHost on the date agreed with you — there is no self-service payout request here.</p>
            </div>
            <div class="evt-section-body evt-table-wrap">
                @if ($payouts->isEmpty())
                    <p class="evt-muted">No payouts recorded yet.</p>
                @else
                    <table class="evt-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payouts as $payout)
                                <tr>
                                    <td>{{ $payout->paid_on->format('j M Y') }}</td>
                                    <td>{{ \App\Support\TicketingSettings::formatZmw($payout->amount) }}</td>
                                    <td>{{ $payout->note ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
