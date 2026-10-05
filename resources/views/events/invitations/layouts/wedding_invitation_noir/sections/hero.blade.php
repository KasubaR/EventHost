@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    [$nameBefore, $nameAfter] = $w->names();

    $heroTag = trim((string) ($invitation['content']['wi2_hero_tag'] ?? ''));
    if ($heroTag === '') {
        $heroTag = 'The Wedding of';
    }

    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
    $dateLine = $event->event_date->format('j').' · '.$event->event_date->format('F').' · '.$event->event_date->format('Y');
@endphp

<section class="wi2-hero">
    <div class="wi2-hero-left">
        <img src="{{ $event->cover_image_url }}" alt="{{ $event->name }}" fetchpriority="high" width="900" height="1200">
    </div>
    <div class="wi2-hero-right">
        <div class="wi2-deco-corner wi2-deco-corner--tr" aria-hidden="true"></div>
        <div class="wi2-deco-corner wi2-deco-corner--br" aria-hidden="true"></div>
        <p class="wi2-hero-tag">{{ $heroTag }}</p>
        <h1 class="wi2-hero-names">
            @if ($nameAfter !== '')
                {{ $nameBefore }}
                <span class="wi2-and-text">and</span>
                <em>{{ $nameAfter }}</em>
            @else
                <em>{{ $nameBefore }}</em>
            @endif
        </h1>
        <hr class="wi2-hero-divider" aria-hidden="true">
        <div class="wi2-hero-info">
            <p>{{ $dateLine }}</p>
            @if ($venueLine !== '' || $locationLine !== '')
                <p class="wi2-hero-venue">
                    @if ($venueLine !== '')
                        {{ $venueLine }}@if ($locationLine !== '')<br>@endif
                    @endif
                    @if ($locationLine !== '')
                        {{ $locationLine }}
                    @endif
                </p>
            @endif
        </div>
        <div class="wi2-hero-cta">
            <a href="#rsvp" class="wi2-cta-btn">Reserve Your Seat</a>
        </div>
    </div>
</section>
