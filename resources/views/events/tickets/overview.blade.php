<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Ticketing — {{ $event->name }}</x-slot>

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

    @include('events.tickets.partials.nav', ['event' => $event, 'active' => 'overview'])

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ number_format($ticketsSold) }}</div>
                <div class="evt-stat-label">Tickets sold</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ number_format($ticketsRemaining) }}</div>
                <div class="evt-stat-label">Tickets remaining</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ number_format($checkedIn) }}</div>
                <div class="evt-stat-label">Checked in</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($grossSales) }}</div>
                <div class="evt-stat-label">Gross sales</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($platformFees) }}</div>
                <div class="evt-stat-label">EventHost fees</div>
            </div>
            <div class="evt-stat-card evt-stat-card--accent">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($hostRevenue) }}</div>
                <div class="evt-stat-label">Host revenue</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($pendingPayout) }}</div>
                <div class="evt-stat-label">Pending payout</div>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-body">
                <p class="evt-muted">
                    Gross sales, fees, and host revenue come from every paid order recorded so far.
                    See <a href="{{ route('events.tickets.index', $event) }}">Tickets</a> for the full list,
                    <a href="{{ route('events.tickets.revenue', $event) }}">Revenue</a> for a full statement, or
                    <a href="{{ route('events.ticket-types.index', $event) }}">Settings</a> to manage ticket types and commission.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
