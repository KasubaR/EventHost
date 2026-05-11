@php
    $typeLabels = [
        'wedding' => 'Wedding',
        'birthday' => 'Birthday',
        'graduation' => 'Graduation',
        'corporate' => 'Corporate Event',
        'baby_shower' => 'Baby Shower',
        'funeral' => 'Memorial',
        'church' => 'Church Event',
    ];
@endphp

<span class="evt-public-type">{{ $typeLabels[$event->event_type] ?? $event->event_type }}</span>
<h1 class="evt-public-title">{{ $event->name }}</h1>

<ul class="evt-detail-list">
    <li><i class="fa-regular fa-calendar"></i> {{ $event->event_date->format('l, F j, Y') }}
        @if ($event->event_time)
            · {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
        @endif
    </li>
    @if ($event->venue)
        <li><i class="fa-solid fa-location-dot"></i> {{ $event->venue }}</li>
    @endif
    @if ($event->location_name)
        <li><i class="fa-regular fa-map"></i> {{ $event->location_name }}</li>
    @endif
    @if ($event->latitude !== null && $event->longitude !== null)
        <li>
            <i class="fa-solid fa-map-location-dot"></i>
            <a href="https://www.google.com/maps?q={{ $event->latitude }},{{ $event->longitude }}" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
        </li>
    @endif
    @if ($event->guest_limit)
        <li><i class="fa-solid fa-users"></i> Guest limit: {{ $event->guest_limit }}</li>
    @endif
    @if ($event->allow_plus_one)
        <li><i class="fa-solid fa-user-plus"></i> Plus-ones welcome</li>
    @endif
    @if ($event->show_guest_list)
        <li><i class="fa-solid fa-list"></i> Guest list visible to attendees</li>
    @endif
</ul>

@php
    $calendarWindow = \App\Support\EventCalendarLinks::window($event);
    $googleCal = $calendarWindow ? \App\Support\EventCalendarLinks::googleCalendarUrl($event) : null;
    $outlookCal = $calendarWindow ? \App\Support\EventCalendarLinks::outlookCalendarUrl($event) : null;
    $icsHref = $calendarWindow && filled($event->slug ?? null)
        ? route('events.public.ics', ['slug' => $event->slug])
        : null;
@endphp
@if ($calendarWindow && ($googleCal || $outlookCal || $icsHref))
    <div class="evt-calendar-actions" role="group" aria-label="Add to calendar">
        <p class="evt-calendar-actions-label">Add to calendar</p>
        <ul class="evt-calendar-actions-list">
            @if ($googleCal)
                <li>
                    <a href="{{ $googleCal }}" class="evt-calendar-btn" target="_blank" rel="noopener noreferrer">
                        <i class="fa-brands fa-google" aria-hidden="true"></i> Google
                    </a>
                </li>
            @endif
            @if ($outlookCal)
                <li>
                    <a href="{{ $outlookCal }}" class="evt-calendar-btn" target="_blank" rel="noopener noreferrer">
                        <i class="fa-regular fa-calendar" aria-hidden="true"></i> Outlook
                    </a>
                </li>
            @endif
            @if ($icsHref)
                <li>
                    <a href="{{ $icsHref }}" class="evt-calendar-btn" download>
                        <i class="fa-brands fa-apple" aria-hidden="true"></i> Apple / .ics
                    </a>
                </li>
            @endif
        </ul>
    </div>
@endif

@if (! $event->is_public)
    <p class="evt-muted evt-inv-private-note"><i class="fa-solid fa-lock"></i> This host marked this event as private in settings.</p>
@endif
