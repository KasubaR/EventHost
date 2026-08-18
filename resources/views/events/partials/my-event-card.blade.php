<article class="evt-card">
    <div class="evt-card-main">
        <img src="{{ $event->cover_image_url }}" alt="" class="evt-card-cover" width="96" height="54">
        <div class="evt-card-body">
            <h3>{{ $event->name }}</h3>
            <p class="evt-card-meta">
                <span class="evt-type-tag">{{ \App\Models\Event::TYPE_LABELS[$event->event_type] ?? $event->event_type }}</span>
                · {{ $event->event_date->format('M j, Y') }}
                @if ($event->event_time)
                    · {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                @endif
            </p>
            <div class="evt-card-badges">
                @if ($event->isTicketed())
                    <span class="evt-badge {{ $event->ticketSalesAreApproved() ? 'evt-badge--live' : 'evt-badge--draft' }}"><i class="fa-solid fa-ticket"></i> {{ $event->ticketing_status->label() }}</span>
                @elseif ($event->is_published)
                    <span class="evt-badge evt-badge--live"><i class="fa-solid fa-circle-check"></i> Published</span>
                @else
                    <span class="evt-badge evt-badge--draft"><i class="fa-solid fa-pen"></i> Draft</span>
                @endif
                @if ($event->is_published && $event->isLocked())
                    <span class="evt-badge evt-badge--done"><i class="fa-solid fa-flag-checkered"></i> Completed</span>
                @endif
            </div>
        </div>
    </div>
    <div class="evt-card-actions">
        {{-- Guests can be managed on drafts too (GuestController only checks the "update"
             event policy, not is_published), so this is unconditional. It's the primary
             action when the sidebar's "Guests & RSVPs" link sent the user here to pick
             an event — see events/index.blade.php. --}}
        @if ($event->isTicketed())
            <a href="{{ route('events.ticket-types.index', $event) }}" class="btn-primary"><i class="fa-solid fa-ticket"></i> Tickets</a>
        @else
            <a href="{{ route('events.guests.index', $event) }}" class="{{ request('from') === 'guests' ? 'btn-primary' : 'evt-btn-outline' }}"><i class="fa-solid fa-users"></i> Guests &amp; RSVPs</a>
        @endif
        <a href="{{ route('events.edit', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
        <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-eye"></i> View</a>
        @if ($event->is_published && $event->is_public)
            <a href="{{ route('events.public', $event->slug) }}" class="evt-btn-outline"><i class="fa-solid fa-link"></i> Public link</a>
        @endif
    </div>
</article>
