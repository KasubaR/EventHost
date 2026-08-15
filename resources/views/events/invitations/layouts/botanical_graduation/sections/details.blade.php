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

<div class="section evt-bg-details-section">
    <p class="section-eyebrow">The occasion</p>
    <h2 class="section-title evt-bg-section-title-h2">Ceremony details</h2>

    <div class="details-row">
        <div class="detail-tile">
            <span class="tile-label">Date &amp; time</span>
            <p class="tile-value">{{ $event->event_date->format('l, F j, Y') }}
                @if ($event->event_time)
                    <br>{{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                @endif
            </p>
        </div>

        @if ($event->venue)
            <div class="detail-tile">
                <span class="tile-label">Venue</span>
                <p class="tile-value">{{ $event->venue }}</p>
            </div>
        @endif

        @if ($event->location_name)
            <div class="detail-tile">
                <span class="tile-label">Location</span>
                <p class="tile-value">{{ $event->location_name }}</p>
            </div>
        @endif

        @if ($event->latitude !== null && $event->longitude !== null)
            <div class="detail-tile">
                <span class="tile-label">Directions</span>
                <p class="tile-value">
                    <a href="https://www.google.com/maps?q={{ $event->latitude }},{{ $event->longitude }}" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
                </p>
            </div>
        @endif

        @if ($event->guest_limit)
            <div class="detail-tile">
                <span class="tile-label">Guests</span>
                <p class="tile-value">Guest limit: {{ $event->guest_limit }}</p>
            </div>
        @endif

        @if ($event->allow_plus_one)
            <div class="detail-tile">
                <span class="tile-label">Plus-ones</span>
                <p class="tile-value">Plus-ones welcome</p>
            </div>
        @endif

        @if ($event->show_guest_list)
            <div class="detail-tile">
                <span class="tile-label">Guest list</span>
                <p class="tile-value">Visible to attendees</p>
            </div>
        @endif
    </div>

    {{-- See events/invitations/sections/details.blade.php — host-only note, hidden from guests. --}}
    @if (! $event->is_public && ! isset($guest))
        <p class="evt-muted evt-inv-private-note evt-bg-private-note"><i class="fa-solid fa-lock"></i> This host marked this event as private in settings.</p>
    @endif
</div>
