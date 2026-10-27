<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <style>
            .admin-aud-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin: 16px 0; overflow-x: auto; }
            .admin-aud-tab { padding: 10px 16px; font-weight: 600; color: #6b7280; text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
            .admin-aud-tab:hover { color: #111827; }
            .admin-aud-tab.is-active { color: #4f46e5; border-bottom-color: #4f46e5; }
            .admin-aud-count { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #f3f4f6; font-size: 12px; color: #374151; }
        </style>
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
        @if ($audience)
            <input type="hidden" name="audience" value="{{ $audience->value }}">
        @endif
        <label class="admin-muted" style="display:flex;align-items:center;gap:6px;">
            <input type="checkbox" name="created_by_admin" value="1" @checked($createdByAdmin)> Created by admin
        </label>
        <button type="submit" class="btn-primary">Search</button>
    </form>

    @php
        $tabQuery = array_filter(['q' => $search, 'created_by_admin' => $createdByAdmin ? 1 : null]);
        $tabs = [['key' => null, 'label' => 'All', 'count' => $audienceCounts->sum()]];
        foreach (\App\Enums\EventAudience::cases() as $case) {
            $tabs[] = ['key' => $case, 'label' => $case->label(), 'count' => $audienceCounts[$case->value] ?? 0];
        }
    @endphp
    <div class="admin-aud-tabs" role="tablist" aria-label="Event audience">
        @foreach ($tabs as $tab)
            <a href="{{ route('admin.events.index', $tabQuery + ($tab['key'] ? ['audience' => $tab['key']->value] : [])) }}"
               role="tab" aria-selected="{{ $audience === $tab['key'] ? 'true' : 'false' }}"
               class="admin-aud-tab {{ $audience === $tab['key'] ? 'is-active' : '' }}">
                {{ $tab['label'] }} <span class="admin-aud-count">{{ $tab['count'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Event</th>
                <th>Owner</th>
                <th>Audience</th>
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
                    <td>
                        {{ $event->name }}
                        @if ($event->created_by_admin_id)
                            <div class="admin-muted"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> Set up by our team</div>
                        @endif
                        @if ($event->trashed())
                            @php $retention = \App\Support\EventRetentionNotice::for($event); @endphp
                            <div class="admin-muted">
                                <i class="fa-solid {{ $retention?->isKept() ? 'fa-lock' : 'fa-trash' }}" aria-hidden="true"></i>
                                Deleted {{ $event->deleted_at->format('M j, Y') }}@if ($retention) · {{ $retention->label }}@endif
                            </div>
                        @endif
                    </td>
                    <td>{{ $event->user?->email ?? '-' }}</td>
                    <td>{{ $event->audience->label() }}</td>
                    <td>{{ \App\Models\Event::TYPE_LABELS[$event->event_type] ?? $event->event_type }}</td>
                    <td>{{ $event->guests_count }}</td>
                    <td>{{ $event->rsvps_count }}</td>
                    <td>{{ $event->is_published ? 'Yes' : 'No' }}</td>
                    <td>{{ $event->created_at->format('M j, Y') }}</td>
                    <td><a href="{{ route('admin.events.show', $event) }}" class="evt-btn-outline evt-btn-tiny">View</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="admin-muted">No events found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($events->hasPages())
        <div class="evt-pagination admin-mt-md">{{ $events->links() }}</div>
    @endif
</x-admin-layout>
