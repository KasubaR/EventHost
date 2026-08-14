{{--
    Public-facing event card. Shared by the homepage strip and /discover.
    Requires css/event-cards.css. Expects: $event
--}}
<a href="{{ route('events.public', $event->slug) }}" class="event-card">
    <div class="event-card-img">
        <img src="{{ $event->cover_image_url }}" alt="" width="400" height="225" loading="lazy" decoding="async">
        <span class="evt-type-tag event-card-tag">{{ $event->event_type_label }}</span>
    </div>
    <div class="event-card-body">
        <h3 class="event-card-title">{{ $event->name }}</h3>
        <p class="event-card-when">
            <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
            <span>
                {{ $event->event_date->format('D, j M Y') }}
                @if ($event->event_time)
                    · {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                @endif
            </span>
        </p>
        @if ($event->venue || $event->location_name)
            <p class="event-card-where">
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                <span>{{ $event->venue ?: $event->location_name }}</span>
            </p>
        @endif
    </div>
</a>
