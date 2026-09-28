@if ($event->latitude !== null && $event->longitude !== null)
    <a href="https://www.google.com/maps?q={{ $event->latitude }},{{ $event->longitude }}" class="{{ $class ?? '' }}" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
@endif
