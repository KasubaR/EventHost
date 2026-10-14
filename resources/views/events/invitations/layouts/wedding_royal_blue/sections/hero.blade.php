@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
@endphp

<section class="rb-hero" id="top">
    <img class="rb-hero-bg" src="{{ $event->cover_image_url }}" alt="" fetchpriority="high" width="1600" height="1000">
    <div class="rb-hero-veil" aria-hidden="true"></div>
    <div class="rb-hero-copy">
        <p class="rb-script">You are cordially invited</p>
        <h1 class="rb-hero-names">
            @if ($nameAfter !== '')
                {{ $nameBefore }} <span class="rb-hero-amp">&amp;</span> {{ $nameAfter }}
            @else
                {{ $nameBefore }}
            @endif
        </h1>
        <p class="rb-hero-tag">Together with their families, they cordially invite you to celebrate their union.</p>
        @include('events.invitations.layouts.wedding_royal_blue.partials.ornament', ['class' => 'rb-ornament--light'])
        <p class="rb-hero-date">{{ $event->event_date->format('l, jS F Y') }}</p>
    </div>
</section>
