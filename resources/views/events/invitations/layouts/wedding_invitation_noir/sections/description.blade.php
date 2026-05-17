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

    $formal = trim((string) ($invitation['content']['wi2_invite_formal'] ?? ''));
    if ($formal === '') {
        $formal = 'Together with their families';
    }

    $body = trim((string) ($invitation['content']['wi2_invite_body'] ?? ''));
    if ($body === '') {
        $body = "request the honour of your presence\nas they exchange vows and begin\ntheir life together in love";
    }

    $timePhrase = '';
    if ($event->event_time) {
        $timePhrase = 'at '.\Carbon\Carbon::parse('2000-01-01 '.substr((string) $event->event_time, 0, 8))->format('g:i A');
    }

    $venueLine = trim((string) $event->venue) ?: trim((string) $event->location_name);
    $locationLine = trim((string) $event->location_name);
@endphp

<section class="wi2-invite-section wi2-reveal" data-wi2-reveal>
    <div class="wi2-invite-card">
        <div class="wi2-invite-ornament" aria-hidden="true">✦ &nbsp; &nbsp; ✦ &nbsp; &nbsp; ✦</div>
        <p class="wi2-invite-formal">{{ $formal }}</p>
        <h2 class="wi2-invite-names">
            {{ $nameBefore }}
            @if ($nameAfter !== '')
                <span class="wi2-invite-ampersand">&amp;</span>
                {{ $nameAfter }}
            @endif
        </h2>
        <hr class="wi2-gold-hr wi2-gold-hr--wide" aria-hidden="true">
        <p class="wi2-invite-body">{!! nl2br(e($body)) !!}</p>
        <hr class="wi2-gold-hr wi2-gold-hr--wide" aria-hidden="true">
        <div class="wi2-invite-detail">
            <span>{{ $event->event_date->format('l') }}</span>
            <span class="wi2-highlight">{{ $event->event_date->format('jS F, Y') }}</span>
            @if ($timePhrase !== '')
                <span>{{ $timePhrase }}</span>
            @endif
            @if ($venueLine !== '')
                <span class="wi2-highlight">{{ $venueLine }}</span>
            @endif
            @if ($locationLine !== '' && $locationLine !== $venueLine)
                <span>{{ $locationLine }}</span>
            @endif
        </div>
        <div class="wi2-invite-ornament" style="margin-top:2rem;margin-bottom:0;" aria-hidden="true">✦ &nbsp; &nbsp; ✦ &nbsp; &nbsp; ✦</div>
    </div>
</section>
