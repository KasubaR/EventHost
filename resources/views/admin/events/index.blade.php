<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Events</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Events</h1>
                <p class="dph-sub">Moderate invitations platform-wide.</p>
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    <form method="get" action="{{ route('admin.events.index') }}" class="admin-filter-bar" role="search">
        <div>
            <label class="evt-sr-only" for="admin-event-q">Search</label>
            <input id="admin-event-q" type="search" name="q" value="{{ $search }}" placeholder="Event name or owner">
        </div>
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Event</th>
                <th>Owner</th>
                <th>Type</th>
                <th>Guests</th>
                <th>RSVPs</th>
                <th>Published</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($events as $event)
                <tr>
                    <td>{{ $event->name }}</td>
                    <td>{{ $event->user?->email ?? '—' }}</td>
                    <td>{{ \App\Models\Event::TYPE_LABELS[$event->event_type] ?? $event->event_type }}</td>
                    <td>{{ $event->guests_count }}</td>
                    <td>{{ $event->rsvps_count }}</td>
                    <td>{{ $event->is_published ? 'Yes' : 'No' }}</td>
                    <td>{{ $event->created_at->format('M j, Y') }}</td>
                    <td><a href="{{ route('admin.events.show', $event) }}" class="evt-btn-outline evt-btn-tiny">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="admin-muted">No events found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($events->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $events->links() }}</div>
    @endif
</x-admin-layout>
