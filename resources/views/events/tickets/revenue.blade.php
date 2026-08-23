<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Revenue — {{ $event->name }}</x-slot>

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

    @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'revenue'])

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($grossSales) }}</div>
                <div class="evt-stat-label">Gross sales</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($platformFees) }}</div>
                <div class="evt-stat-label">EventHost fees</div>
            </div>
            <div class="evt-stat-card evt-stat-card--accent">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($pendingPayable) }}</div>
                <div class="evt-stat-label">Pending payout</div>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Statement</h2>
                <p>Every sale and payout recorded against this event, newest first. See <a href="{{ route('events.tickets.payouts', $event) }}">Payouts</a> for a summary of what's been paid out.</p>
            </div>
            <div class="evt-section-body evt-table-wrap">
                @if ($entries->isEmpty())
                    <p class="evt-muted">No revenue recorded yet.</p>
                @else
                    <table class="evt-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Gross</th>
                                <th>Platform fee</th>
                                <th>Amount</th>
                                <th>Balance after</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td>{{ $entry->created_at->format('j M Y H:i') }}</td>
                                    <td>{{ $entry->typeLabel() }}</td>
                                    <td>{{ \App\Support\TicketingSettings::formatZmw($entry->gross_amount) }}</td>
                                    <td>{{ \App\Support\TicketingSettings::formatZmw($entry->platform_fee) }}</td>
                                    <td>
                                        {{-- host_amount is signed (payout rows are negative) — TicketingSettings::formatZmw()
                                             expects a non-negative amount everywhere else it's used, so the sign is handled
                                             here rather than changing that shared helper's behavior. --}}
                                        @if ($entry->host_amount < 0)-@endif{{ \App\Support\TicketingSettings::formatZmw(abs($entry->host_amount)) }}
                                    </td>
                                    <td>{{ \App\Support\TicketingSettings::formatZmw($entry->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="evt-pagination">{{ $entries->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
