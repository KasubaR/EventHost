<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @php
        $ev = $adminEvent;
    @endphp

    <x-slot name="title">{{ $ev->name }} · Guests</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.events.index') }}">Events</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <a href="{{ route('admin.events.show', $ev) }}">{{ $ev->name }}</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>Guests</span>
                </nav>
                <h1 class="dph-title">{{ $ev->name }}</h1>
                <p class="dph-sub">Owner: {{ $ev->user?->email ?? '—' }}</p>
            </div>
            <div class="admin-actions">
                <a href="{{ route('admin.events.show', $ev) }}" class="evt-btn-outline dash-header-cta">Back to event</a>
            </div>
        </div>
    </x-slot>

    <form method="get" action="{{ route('admin.events.guests', $ev) }}" class="admin-filter-bar" role="search">
        <div>
            <label class="evt-sr-only" for="admin-guest-q">Search</label>
            <input id="admin-guest-q" type="search" name="q" value="{{ $search }}" placeholder="Guest name or email">
        </div>
        <button type="submit" class="btn-primary">Search</button>
    </form>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Guest</th>
                <th>Email</th>
                <th>Added</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($guests as $guest)
                <tr>
                    <td>{{ $guest->name }}</td>
                    <td>{{ $guest->email ?? '—' }}</td>
                    <td>{{ $guest->created_at->format('M j, Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="admin-muted">No guests match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($guests->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $guests->links() }}</div>
    @endif
</x-admin-layout>
