<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    @php
        $ev = $adminEvent;
    @endphp

    <x-slot name="title">{{ $ev->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('admin.events.index') }}">Events</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>{{ $ev->name }}</span>
                </nav>
                <h1 class="dph-title">{{ $ev->name }}</h1>
                <p class="dph-sub">Owner: {{ $ev->user?->email ?? '—' }}</p>
            </div>
            <div class="admin-actions">
                <a href="{{ route('admin.events.index') }}" class="evt-btn-outline dash-header-cta">Back</a>
                @if ($ev->is_published)
                    <a href="{{ route('events.public', $ev->slug) }}" class="btn-primary dash-header-cta" target="_blank" rel="noopener">Public page</a>
                @endif
            </div>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> {{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <div class="admin-detail-grid">
        <div class="admin-panel-card">
            <h2>Summary</h2>
            <p class="admin-muted admin-mt-sm">Type: {{ $ev->event_type }}</p>
            <p class="admin-muted">Guests: {{ $ev->guests_count }} · RSVPs: {{ $ev->rsvps_count }}</p>
            <p class="admin-muted">Published: {{ $ev->is_published ? 'Yes' : 'No' }}</p>
            <p class="admin-muted">Public RSVP allowed: {{ $ev->is_public ? 'Yes' : 'No' }}</p>

            @can('events.publish_toggle')
                <form method="post" action="{{ route('admin.events.publish', $ev) }}" class="profile-form admin-mt-md">
                    @csrf
                    @method('PATCH')
                    <fieldset>
                        <legend class="admin-muted">Public invitation visibility</legend>
                        <label class="admin-mt-sm">
                            <input type="hidden" name="is_published" value="0">
                            <input type="checkbox" name="is_published" value="1" @checked($ev->is_published)>
                            Published (live invitation)
                        </label>
                    </fieldset>
                    <div class="admin-actions admin-mt-md">
                        <button type="submit" class="btn-primary">Save publish state</button>
                    </div>
                </form>
            @endcan

            @can('events.delete')
                <form method="post" action="{{ route('admin.events.destroy', $ev) }}" class="profile-form admin-mt-md" data-confirm="Permanently delete this event and related guests/RSVPs?">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="evt-btn-danger-outline">Delete event</button>
                </form>
            @endcan
        </div>

        <div class="admin-panel-card">
            <h2>Owner account</h2>
            <p class="admin-muted admin-mt-sm">{{ $ev->user?->name ?? '—' }}</p>
            <p class="admin-muted">{{ $ev->user?->phone ?? 'No phone' }} · Status {{ $ev->user?->status ?? '—' }}</p>
            @can('users.view')
                @if ($ev->user)
                    <p class="admin-mt-md"><a href="{{ route('admin.users.show', $ev->user) }}">Open user</a></p>
                @endif
            @endcan
        </div>
    </div>
</x-admin-layout>
