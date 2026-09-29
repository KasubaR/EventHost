{{--
    Shared by every invitation layout's Location section plus the ticket landing page, so the
    Google Maps embed/Directions logic lives in one place instead of drifting across templates.
    Params: $class (optional, applied to both links — matches each template's own link styling),
    $embed (optional, default false — only pass true from a section with room for an iframe;
    the compact per-field tile layouts just get the two links).
--}}
@php
    $hasCoords = $event->latitude !== null && $event->longitude !== null;
    $mapsKey = config('services.google_maps.key');
@endphp
@if ($hasCoords)
    @php
        $mapsQuery = $event->google_place_id ? 'place_id:'.$event->google_place_id : $event->latitude.','.$event->longitude;
        $directionsDestination = $event->formatted_address ?: ($event->latitude.','.$event->longitude);
    @endphp
    @if (($embed ?? false) && $mapsKey)
        <div class="evt-map-embed">
            <iframe
                src="https://www.google.com/maps/embed/v1/place?key={{ $mapsKey }}&q={{ urlencode($mapsQuery) }}"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                allowfullscreen
                title="Map showing {{ $event->venue ?: $event->name }}"
            ></iframe>
        </div>
    @endif
    <div class="evt-map-links">
        <a href="https://www.google.com/maps?q={{ urlencode($mapsQuery) }}" class="{{ $class ?? '' }}" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
        <a href="https://www.google.com/maps/dir/?api=1&destination={{ urlencode($directionsDestination) }}{{ $event->google_place_id ? '&destination_place_id='.urlencode($event->google_place_id) : '' }}" class="{{ $class ?? '' }}" target="_blank" rel="noopener noreferrer">Get Directions</a>
    </div>
@endif
