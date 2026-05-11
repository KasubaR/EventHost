<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">RSVPs</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">RSVPs</h1>
                <p class="dph-sub">Monitor responses platform-wide.</p>
            </div>
        </div>
    </x-slot>

    <form method="get" action="{{ route('admin.rsvps.index') }}" class="admin-filter-bar" role="search">
        <div>
            <label class="evt-sr-only" for="admin-rsvp-q">Search</label>
            <input id="admin-rsvp-q" type="search" name="q" value="{{ $search }}" placeholder="Guest or event">
        </div>
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Guest</th>
                <th>Status</th>
                <th>Headcount</th>
                <th>Event</th>
                <th>Organizer</th>
                <th>Updated</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rsvps as $rsvp)
                <tr>
                    <td>{{ $rsvp->guest?->name ?? '—' }}</td>
                    <td>{{ $rsvp->status instanceof \BackedEnum ? $rsvp->status->value : $rsvp->status }}</td>
                    <td>{{ $rsvp->attendee_count }}</td>
                    <td>{{ $rsvp->event?->name ?? '—' }}</td>
                    <td>{{ $rsvp->event?->user?->email ?? '—' }}</td>
                    <td>{{ $rsvp->updated_at->format('M j, Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="admin-muted">No RSVPs match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($rsvps->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $rsvps->links() }}</div>
    @endif
</x-admin-layout>
