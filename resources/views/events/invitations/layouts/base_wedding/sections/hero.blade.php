@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    [$nameBefore, $nameAfter] = $w->names();

    $eyebrow = trim((string) ($invitation['content']['wi_hero_eyebrow'] ?? ''));
    if ($eyebrow === '') {
        $eyebrow = 'Together with their families';
    }

    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $audioPath = $invitation['effects']['audio_track'] ?? null;
@endphp

<header class="bw-hero" id="home">
    <p class="bw-eyebrow">{{ $eyebrow }}</p>
    <h1 class="bw-names">
        @if ($nameAfter !== '')
            <span class="bw-name">{{ $nameBefore }}</span>
            <span class="bw-amp">&amp;</span>
            <span class="bw-name">{{ $nameAfter }}</span>
        @else
            <span class="bw-name">{{ $nameBefore }}</span>
        @endif
    </h1>
    <p class="bw-hero-request">request the pleasure of your company</p>
    <div class="bw-orn" aria-hidden="true"><span></span></div>
    <p class="bw-hero-date">{{ $w->dateLine() }}</p>
    @if ($timeLine !== null || $venueLine !== '')
        <p class="bw-hero-meta">
            {{ collect([$timeLine, $venueLine])->filter()->implode(' · ') }}
        </p>
    @endif

    @if ($audioPath)
        <div class="bw-audio">
            <button type="button" class="evt-inv-audio-play" data-inv-audio-play data-audio-src="{{ asset('storage/'.$audioPath) }}">
                <i class="fa-solid fa-music" aria-hidden="true"></i>
                <span class="evt-inv-audio-label">Play music</span>
            </button>
        </div>
    @endif
</header>
