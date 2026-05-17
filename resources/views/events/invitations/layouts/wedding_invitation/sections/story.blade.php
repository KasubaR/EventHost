@php
    $story = trim((string) ($invitation['content']['story'] ?? ''));
@endphp

@if ($story !== '')
    @php
        $storyImg = $event->cover_image_url;
        $gallery = array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? [])));
        if (count($gallery) > 0) {
            $storyImg = asset('storage/'.$gallery[0]);
        }
    @endphp

    <section class="wi-story-section wi-reveal" data-wi-reveal>
        <div class="wi-story-inner">
            <div class="wi-story-text">
                <p class="wi-section-tag">Our Story</p>
                <h2 class="wi-section-title">How it all <em>began</em></h2>
                <hr class="wi-gold-rule" aria-hidden="true">
                <div class="wi-section-body">{!! nl2br(e($story)) !!}</div>
            </div>
            <div class="wi-story-img-wrap">
                <img src="{{ $storyImg }}" alt="" loading="lazy" width="700" height="440">
            </div>
        </div>
    </section>
@endif
