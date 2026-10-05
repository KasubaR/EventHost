@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    [$nameBefore, $nameAfter] = $w->names();
    $location = trim((string) $event->location_name) ?: $w->venueLine();
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
    <figure class="mm-hero-photo">
        <img src="{{ $event->cover_image_url }}" alt="{{ $event->name }}" fetchpriority="high" width="900" height="1125">
    </figure>
    <a href="#countdown" class="mm-scroll-hint">Scroll</a>
</section>
