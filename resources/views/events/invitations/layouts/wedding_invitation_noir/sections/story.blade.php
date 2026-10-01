@php
    $story = \App\Support\WeddingInvitationView::for($event, $invitation)->story();

    $quote = trim((string) ($invitation['content']['wi2_photo_quote'] ?? ''));
    if ($quote === '') {
        $quote = '"Two souls with but a single thought, two hearts that beat as one."';
    }

    $cite = trim((string) ($invitation['content']['wi2_photo_quote_cite'] ?? ''));
    if ($cite === '') {
        $cite = 'Friedrich Halm';
    }

    $photoSrc = $event->cover_image_url;
    $gallery = array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? [])));
    if (count($gallery) > 1) {
        $photoSrc = \App\Support\InvitationMediaUrl::resolve($gallery[1]);
    } elseif (count($gallery) > 0) {
        $photoSrc = \App\Support\InvitationMediaUrl::resolve($gallery[0]);
    }
@endphp

<div class="wi2-photo-bleed wi2-reveal @if ($story !== '') wi2-photo-bleed--story @endif" data-wi2-reveal>
    <img src="{{ $photoSrc }}" alt="" loading="lazy" width="1600" height="900">
    <div class="wi2-photo-bleed-text">
        @if ($story !== '')
            <div class="wi2-story">
                <span class="wi2-section-kicker">Our Story</span>
                <h2 class="wi2-section-heading">How it <em>began</em></h2>
                <hr class="wi2-gold-hr" aria-hidden="true">
                <p class="wi2-story-body">{!! nl2br(e($story)) !!}</p>
            </div>
        @else
            <blockquote class="wi2-photo-quote">
                <p>{!! nl2br(e($quote)) !!}</p>
                <cite>{{ $cite }}</cite>
            </blockquote>
        @endif
    </div>
</div>
