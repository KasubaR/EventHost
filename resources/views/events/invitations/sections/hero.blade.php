@php
    $videoPath = $invitation['effects']['video_background'] ?? null;
    $audioPath = $invitation['effects']['audio_track'] ?? null;
@endphp

<div class="evt-public-hero evt-inv-hero @if ($videoPath) evt-inv-hero--video @endif">
    @if ($videoPath)
        <video
            class="evt-inv-hero-video"
            muted
            loop
            playsinline
            autoplay
            poster="{{ $event->cover_image_url }}"
            aria-hidden="true"
        >
            <source src="{{ asset('storage/'.$videoPath) }}" type="{{ str_ends_with(strtolower($videoPath), '.webm') ? 'video/webm' : 'video/mp4' }}">
        </video>
        <div class="evt-inv-hero-video-scrim" aria-hidden="true"></div>
    @endif
    <img src="{{ $event->cover_image_url }}" alt="" class="evt-public-cover evt-inv-hero-cover" width="1200" height="630">

    @if ($audioPath)
        <div class="evt-inv-audio-wrap">
            <button type="button" class="evt-inv-audio-play" data-inv-audio-play data-audio-src="{{ asset('storage/'.$audioPath) }}">
                <i class="fa-solid fa-music" aria-hidden="true"></i>
                <span class="evt-inv-audio-label">Play music</span>
            </button>
        </div>
    @endif
</div>
