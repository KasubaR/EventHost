@php
    $intro = trim((string) $event->description);
    if ($intro === '') {
        $intro = 'We joyfully invite you to celebrate the union of two souls as they begin their forever journey together in love and laughter.';
    } else {
        $lines = preg_split('/\R/u', $intro, 2);
        $intro = trim((string) ($lines[0] ?? ''));
        if (strlen($intro) > 500) {
            $intro = \Illuminate\Support\Str::limit($intro, 500, '…');
        }
    }

    $wiMediaSrc = static function (string $pathOrUrl): string {
        if ($pathOrUrl === '') {
            return '';
        }
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://') || str_starts_with($pathOrUrl, '/')) {
            return $pathOrUrl;
        }

        return asset('storage/'.$pathOrUrl);
    };

    $couplePaths = array_values(array_filter(array_map('strval', $invitation['media']['couple_photos'] ?? [])));
    $fallback = $event->cover_image_url;
    if (count($couplePaths) === 0) {
        $couplePaths = [$fallback, $fallback, $fallback];
    } else {
        while (count($couplePaths) < 3) {
            $couplePaths[] = $couplePaths[count($couplePaths) - 1] ?? $fallback;
        }
    }
    $couplePaths = array_slice($couplePaths, 0, 3);

    $caption = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
    if ($caption === '') {
        $caption = 'Two hearts, one story';
    }
@endphp

<section class="wi-save-date wi-reveal" id="save-the-date" data-wi-reveal>
    <p class="wi-section-tag">You are cordially invited</p>
    <h2 class="wi-section-title">Save the <em>Date</em></h2>
    <div class="wi-orn" aria-hidden="true">— ◆ —</div>
    <p class="wi-section-body">{{ $intro }}</p>
    <div class="wi-date-badge">
        <span class="wi-date-badge-weekday">{{ $event->event_date->format('l') }}</span>
        <span class="wi-date-badge-day">{{ $event->event_date->format('j') }}</span>
        <span class="wi-date-badge-month">{{ $event->event_date->format('F Y') }}</span>
    </div>
</section>

<section class="wi-couple-section wi-reveal" data-wi-reveal>
    <div class="wi-couple-grid">
        @foreach (['wi-couple-img-1', 'wi-couple-img-2', 'wi-couple-img-3'] as $idx => $class)
            <div class="wi-couple-img-wrap {{ $class }}">
                <img src="{{ $wiMediaSrc($couplePaths[$idx]) }}" alt="" loading="lazy" width="600" height="420">
            </div>
        @endforeach
    </div>
    <p class="wi-couple-caption">{{ $caption }}</p>
</section>
