@php
    use App\Support\InvitationVideoBackground;

    $videoRaw = $invitation['effects']['video_background'] ?? null;
    $videoRaw = is_string($videoRaw) && $videoRaw !== '' ? $videoRaw : null;
    $videoYoutubeId = InvitationVideoBackground::extractIdFromStored($videoRaw);
    $videoFilePath = InvitationVideoBackground::isFilePath($videoRaw) ? $videoRaw : null;
    $videoEmbedSrc = $videoYoutubeId !== null ? InvitationVideoBackground::embedUrl($videoYoutubeId) : null;

    $audioPath = $invitation['effects']['audio_track'] ?? null;
@endphp

<div class="evt-public-hero evt-inv-hero @if ($videoEmbedSrc || $videoFilePath) evt-inv-hero--video @endif">
    @if ($videoEmbedSrc)
        <div class="evt-inv-hero-video-embed" aria-hidden="true">
            <iframe
                class="evt-inv-hero-video-iframe"
                src="{{ $videoEmbedSrc }}"
                title="Background video"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                referrerpolicy="strict-origin-when-cross-origin"
            ></iframe>
        </div>
        <div class="evt-inv-hero-video-scrim" aria-hidden="true"></div>
    @elseif ($videoFilePath)
        <video
            class="evt-inv-hero-video"
            muted
            loop
            playsinline
            autoplay
            poster="{{ $event->cover_image_url }}"
            aria-hidden="true"
        >
            <source src="{{ asset('storage/'.$videoFilePath) }}" type="{{ str_ends_with(strtolower($videoFilePath), '.webm') ? 'video/webm' : 'video/mp4' }}">
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
