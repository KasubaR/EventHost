@php
    $nameTrim = trim((string) $event->name);
    $nameBefore = $nameTrim;
    $nameAfter = '';
    $ampersandPos = strpos($nameTrim, '&');
    if ($ampersandPos !== false) {
        $nameBefore = trim(substr($nameTrim, 0, $ampersandPos)) ?: $nameTrim;
        $nameAfter = trim(substr($nameTrim, $ampersandPos + 1));
    } elseif (str_contains($nameTrim, ' and ')) {
        $parts = preg_split('/\s+and\s+/i', $nameTrim, 2);
        if (is_array($parts) && count($parts) === 2) {
            $nameBefore = trim($parts[0]);
            $nameAfter = trim($parts[1]);
        }
    }

    $location = trim((string) $event->location_name);
    if ($location === '') {
        $location = trim((string) $event->venue);
    }
@endphp

<section class="mm-hero" id="top">
    @if ($nameAfter !== '')
        <h1 class="mm-hero-name">{{ $nameBefore }}</h1>
        <span class="mm-hero-ampersand" aria-hidden="true">&amp;</span>
        <h1 class="mm-hero-name">{{ $nameAfter }}</h1>
    @else
        <h1 class="mm-hero-name">{{ $nameBefore }}</h1>
    @endif
    <p class="mm-hero-date">{{ $event->event_date->format('F j, Y') }}</p>
    @if ($location !== '')
        <p class="mm-hero-location">{{ $location }}</p>
    @endif
    <a href="#countdown" class="mm-scroll-hint">Scroll</a>
</section>
