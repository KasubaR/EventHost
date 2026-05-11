<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/dashboard-home.css') }}">
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @push('scripts')
        @vite(['resources/js/admin-analytics-charts.js'])
    @endpush

    <x-slot name="title">Analytics</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Platform analytics</h1>
                <p class="dph-sub">Cross-tenant trends for registrations, events, and RSVPs.</p>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="evt-btn-outline dash-header-cta">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Dashboard
            </a>
        </div>
    </x-slot>

    @php
        $adminAnalyticsChartPayload = $charts;
    @endphp

    <script type="application/json" id="admin-analytics-json">@json($adminAnalyticsChartPayload)</script>

    <div data-admin-analytics-root data-admin-analytics-json-id="admin-analytics-json">
        <div class="dash-chart-grid">
            <section class="dash-panel" aria-labelledby="admin-daily-rsvps-title">
                <div class="dash-panel-head">
                    <h2 id="admin-daily-rsvps-title" class="dash-panel-title">Daily RSVPs</h2>
                    <p class="dash-panel-sub">Last 14 days · entire platform</p>
                </div>
                <div class="dash-analytics-chart-wrap" data-admin-chart="daily">
                    <canvas role="img" aria-label="Daily RSVP counts chart"></canvas>
                </div>
            </section>

            <section class="dash-panel" aria-labelledby="admin-status-title">
                <div class="dash-panel-head">
                    <h2 id="admin-status-title" class="dash-panel-title">RSVP outcomes</h2>
                    <p class="dash-panel-sub">All events combined</p>
                </div>
                <div class="dash-analytics-chart-wrap dash-analytics-chart-wrap--donut" data-admin-chart="status">
                    <canvas role="img" aria-label="RSVP status distribution chart"></canvas>
                </div>
            </section>

            <section class="dash-panel" aria-labelledby="admin-monthly-users-title">
                <div class="dash-panel-head">
                    <h2 id="admin-monthly-users-title" class="dash-panel-title">New registrations</h2>
                    <p class="dash-panel-sub">Trailing 12 months</p>
                </div>
                <div class="dash-analytics-chart-wrap" data-admin-chart="monthly_users">
                    <canvas role="img" aria-label="Monthly user registrations chart"></canvas>
                </div>
            </section>

            <section class="dash-panel" aria-labelledby="admin-weekly-events-title">
                <div class="dash-panel-head">
                    <h2 id="admin-weekly-events-title" class="dash-panel-title">Events created</h2>
                    <p class="dash-panel-sub">Last 8 weeks</p>
                </div>
                <div class="dash-analytics-chart-wrap" data-admin-chart="weekly_events">
                    <canvas role="img" aria-label="Weekly events created chart"></canvas>
                </div>
            </section>

            <section class="dash-panel dash-panel--wide" aria-labelledby="admin-event-types-title">
                <div class="dash-panel-head">
                    <h2 id="admin-event-types-title" class="dash-panel-title">Event types</h2>
                    <p class="dash-panel-sub">Share of all events</p>
                </div>
                <div class="dash-analytics-chart-wrap dash-analytics-chart-wrap--donut" data-admin-chart="event_types">
                    <canvas role="img" aria-label="Event types chart"></canvas>
                </div>
            </section>
        </div>
    </div>
</x-admin-layout>
