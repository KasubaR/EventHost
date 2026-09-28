@php
    $audioPath = $invitation['effects']['audio_track'] ?? null;
@endphp

<div class="evt-public-hero evt-inv-std-hero">
    <p class="evt-inv-std-hero-eyebrow">You're invited</p>
    <p class="evt-inv-std-hero-date">{{ $event->event_date->format('l · j F Y') }}</p>

    @if ($audioPath)
        <div class="evt-inv-std-hero-audio">
            <button type="button" class="evt-inv-audio-play" data-inv-audio-play data-audio-src="{{ asset('storage/'.$audioPath) }}">
                <i class="fa-solid fa-music" aria-hidden="true"></i>
                <span class="evt-inv-audio-label">Play music</span>
            </button>
        </div>
    @endif
</div>
