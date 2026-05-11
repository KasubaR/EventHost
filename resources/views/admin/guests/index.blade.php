<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Guests</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Guests</h1>
                <p class="dph-sub">Read-only directory across all events.</p>
            </div>
        </div>
    </x-slot>

    <form method="get" action="{{ route('admin.guests.index') }}" class="admin-filter-bar" role="search">
        <div>
            <label class="evt-sr-only" for="admin-guest-q">Search</label>
            <input id="admin-guest-q" type="search" name="q" value="{{ $search }}" placeholder="Guest name, email, event">
        </div>
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Guest</th>
                <th>Email</th>
                <th>Event</th>
                <th>Organizer</th>
                <th>Added</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($guests as $guest)
                <tr>
                    <td>{{ $guest->name }}</td>
                    <td>{{ $guest->email ?? '—' }}</td>
                    <td>{{ $guest->event?->name ?? '—' }}</td>
                    <td>{{ $guest->event?->user?->email ?? '—' }}</td>
                    <td>{{ $guest->created_at->format('M j, Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="admin-muted">No guests match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($guests->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $guests->links() }}</div>
    @endif
</x-admin-layout>
