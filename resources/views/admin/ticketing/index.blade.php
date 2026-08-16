<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Ticketing</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Ticket sales</h1>
                <p class="dph-sub">Review ticketed events before buyers can pay through EventHost.</p>
            </div>
        </div>
    </x-slot>

    @php
        $filters = [
            \App\Enums\TicketingStatus::PendingReview->value => 'Awaiting review',
            \App\Enums\TicketingStatus::Approved->value => 'Approved',
            \App\Enums\TicketingStatus::Rejected->value => 'Declined',
            \App\Enums\TicketingStatus::Draft->value => 'Drafts',
            'all' => 'All ticketed',
        ];
    @endphp

    <div class="admin-panel-card">
        <nav class="admin-filter-row" aria-label="Ticketing status">
            @foreach ($filters as $value => $label)
                <a href="{{ route('admin.ticketing.index', ['status' => $value]) }}"
                   class="evt-btn-outline {{ $status === $value ? 'is-active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    <div class="admin-panel-card admin-mt-lg">
        @if ($events->isEmpty())
            <p class="admin-muted">Nothing in this queue.</p>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Organizer</th>
                        <th>Types</th>
                        <th>Status</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.ticketing.show', $row) }}">{{ $row->name }}</a>
                            </td>
                            <td>{{ $row->user?->email ?? '—' }}</td>
                            <td>{{ $row->ticket_types_count }}</td>
                            <td>{{ $row->ticketing_status->label() }}</td>
                            <td>{{ $row->ticketing_submitted_at?->format('j M Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="evt-pagination">{{ $events->links() }}</div>
        @endif
    </div>
</x-admin-layout>
