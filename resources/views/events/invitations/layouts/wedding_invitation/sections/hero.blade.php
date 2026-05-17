@php
    $nameTrim = trim((string) $event->name);
    $nameLower = strtolower($nameTrim);
    $forPos = strpos($nameLower, ' for ');
    $nameBefore = $nameTrim;
    $nameAfter = '';
    if ($forPos !== false) {
        $nameBefore = trim(substr($nameTrim, 0, $forPos)) ?: $nameTrim;
        $nameAfter = trim(substr($nameTrim, $forPos + 4));
    } else {
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
    }

    $eyebrow = trim((string) ($invitation['content']['wi_hero_eyebrow'] ?? ''));
    if ($eyebrow === '') {
        $eyebrow = 'Together with their families';
    }

    $heroDate = $event->event_date->format('l').' · '.$event->event_date->format('j F Y');
    $coverUrl = $event->cover_image_url;
@endphp

<div class="wi-hero" id="home">
    <div
        class="wi-hero-img"
        style="background-image: url('{{ e($coverUrl) }}');"
        aria-hidden="true"
    ></div>
    <div class="wi-hero-overlay" aria-hidden="true"></div>

    <div class="wi-hero-content">
        <p class="wi-hero-eyebrow">{{ $eyebrow }}</p>
        <h1 class="wi-hero-names">
            @if ($nameAfter !== '')
                {{ $nameBefore }}
                <span class="wi-amp">&amp;</span>
                {{ $nameAfter }}
            @else
                {{ $nameBefore }}
            @endif
        </h1>
        <hr class="wi-gold-rule" aria-hidden="true">
        <p class="wi-hero-date">{{ $heroDate }}</p>
    </div>

    <a href="#save-the-date" class="wi-hero-scroll">
        <div class="wi-scroll-line" aria-hidden="true"></div>
        <span>Scroll</span>
    </a>
</div>
