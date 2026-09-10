<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Contributions Revenue</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Contributions Revenue</h1>
                <p class="dph-sub">Platform-wide totals across every event with contributions enabled — drill into an event for its own breakdown.</p>
            </div>
        </div>
    </x-slot>

    <div class="evt-grid-2 evt-rsvp-summary-grid admin-mt-lg">
        <div class="evt-stat-card">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($summary['today_collected']) }}</div>
            <div class="evt-stat-label">Collected today</div>
        </div>
        <div class="evt-stat-card">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($summary['total_collected']) }}</div>
            <div class="evt-stat-label">Collected lifetime</div>
        </div>
        <div class="evt-stat-card evt-stat-card--accent">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($summary['pending_payouts']) }}</div>
            <div class="evt-stat-label">Pending host payouts</div>
        </div>
        <div class="evt-stat-card">
            <div class="evt-stat-value">{{ \App\Support\TicketingSettings::formatZmw($summary['completed_payouts']) }}</div>
            <div class="evt-stat-label">Completed payouts</div>
        </div>
    </div>

    <div class="admin-panel-card admin-mt-lg">
        @if ($rows->isEmpty())
            <p class="admin-muted">No contributions collected yet.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Collected</th>
                        <th>Pending payout</th>
                        <th>Paid out</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.contributions.revenue.show', $row->event) }}">{{ $row->event->name }}</a>
                            </td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($row->collected) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($row->pending_payable) }}</td>
                            <td>{{ \App\Support\TicketingSettings::formatZmw($row->paid_out) }}</td>
                            <td><a href="{{ route('admin.contributions.revenue.show', $row->event) }}" class="evt-btn-outline evt-btn-tiny">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="evt-pagination">{{ $rows->links() }}</div>
        @endif
    </div>
</x-admin-layout>
