<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @php
        $ev = $adminEvent;
    @endphp

    <x-slot name="title">{{ $ev->name }} · RSVPs</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.events.index') }}">Events</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <a href="{{ route('admin.events.show', $ev) }}">{{ $ev->name }}</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>RSVPs</span>
                </nav>
                <h1 class="dph-title">{{ $ev->name }}</h1>
                <p class="dph-sub">Owner: {{ $ev->user?->email ?? '—' }}</p>
            </div>
            <div class="admin-actions">
                <a href="{{ route('admin.events.show', $ev) }}" class="evt-btn-outline dash-header-cta">Back to event</a>
            </div>
        </div>
    </x-slot>

    <form method="get" action="{{ route('admin.events.rsvps', $ev) }}" class="admin-filter-bar" role="search">
        <div>
            <label class="evt-sr-only" for="admin-rsvp-q">Search</label>
            <input id="admin-rsvp-q" type="search" name="q" value="{{ $search }}" placeholder="Guest name or email">
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
                <th>Updated</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rsvps as $rsvp)
                <tr>
                    <td>{{ $rsvp->guest?->name ?? '—' }}</td>
                    <td>{{ $rsvp->status instanceof \BackedEnum ? $rsvp->status->value : $rsvp->status }}</td>
                    <td>{{ $rsvp->attendee_count }}</td>
                    <td>{{ $rsvp->updated_at->format('M j, Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="admin-muted">No RSVPs match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($rsvps->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $rsvps->links() }}</div>
    @endif
</x-admin-layout>
