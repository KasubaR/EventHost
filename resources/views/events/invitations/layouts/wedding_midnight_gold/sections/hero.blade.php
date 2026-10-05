@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
@endphp

<section class="mg-hero" id="top">
    <img class="mg-hero-bg" src="{{ $event->cover_image_url }}" alt="" fetchpriority="high" width="1600" height="1000">
    <div class="mg-hero-frame">
        <h1 class="mg-hero-names">
            @if ($nameAfter !== '')
                {{ $nameBefore }} <span class="mg-hero-amp">&amp;</span> {{ $nameAfter }}
            @else
                {{ $nameBefore }}
            @endif
        </h1>
        <p class="mg-hero-tag">We're getting married!</p>
        <hr class="mg-rule">
        <p class="mg-hero-date">{{ $event->event_date->format('l, F jS, Y') }}</p>
    </div>
</section>
