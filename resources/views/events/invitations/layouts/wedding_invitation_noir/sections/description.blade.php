@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    [$nameBefore, $nameAfter] = $w->names();

    $formal = trim((string) ($invitation['content']['wi2_invite_formal'] ?? ''));
    if ($formal === '') {
        $formal = 'Together with their families';
    }

    $body = trim((string) ($invitation['content']['wi2_invite_body'] ?? ''));
    if ($body === '') {
        $body = "request the honour of your presence\nas they exchange vows and begin\ntheir life together in love";
    }

    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();

    $couplePhotos = $w->couplePhotos();
    $caption = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
    if ($caption === '') {
        $caption = 'Two souls, one promise';
    }
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
            @if ($timeLine !== null)
                <span>at {{ $timeLine }}</span>
            @endif
            @if ($venueLine !== '')
                <span class="wi2-highlight">{{ $venueLine }}</span>
            @endif
            @if ($locationLine !== '')
                <span>{{ $locationLine }}</span>
            @endif
        </div>
        <div class="wi2-invite-ornament wi2-invite-ornament--end" aria-hidden="true">✦ &nbsp; &nbsp; ✦ &nbsp; &nbsp; ✦</div>
    </div>
</section>

<section class="wi2-couple-section wi2-reveal" data-wi2-reveal>
    <div class="wi2-couple-grid">
        @foreach ($couplePhotos as $idx => $src)
            <div class="wi2-couple-frame wi2-couple-frame--{{ $idx + 1 }}">
                <img src="{{ $src }}" alt="" loading="lazy" width="600" height="800">
            </div>
        @endforeach
    </div>
    <p class="wi2-couple-caption">{{ $caption }}</p>
</section>
