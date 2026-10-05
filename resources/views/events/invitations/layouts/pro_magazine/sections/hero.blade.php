@php
    use App\Support\InvitationVideoBackground;

    $videoRaw = $invitation['effects']['video_background'] ?? null;
    $videoRaw = is_string($videoRaw) && $videoRaw !== '' ? $videoRaw : null;
    $videoYoutubeId = InvitationVideoBackground::extractIdFromStored($videoRaw);
    $videoFilePath = InvitationVideoBackground::isFilePath($videoRaw) ? $videoRaw : null;
    $videoEmbedSrc = $videoYoutubeId !== null ? InvitationVideoBackground::embedUrl($videoYoutubeId) : null;

    $audioPath = $invitation['effects']['audio_track'] ?? null;
@endphp

<div class="evt-inv-pm-hero-shell">
    <div class="evt-inv-pm-masthead" aria-hidden="true">
        <span class="evt-inv-pm-masthead-line"></span>
        <span class="evt-inv-pm-masthead-word">{{ config('app.name') }}</span>
        <span class="evt-inv-pm-masthead-line"></span>
    </div>

    <div class="evt-public-hero evt-inv-hero evt-inv-pm-hero @if ($videoEmbedSrc || $videoFilePath) evt-inv-hero--video @endif">
        @include('events.invitations.partials.hero-video', ['event' => $event, 'videoRaw' => $videoRaw, 'videoEmbedSrc' => $videoEmbedSrc, 'videoFilePath' => $videoFilePath])
        <img src="{{ $event->cover_image_url }}" alt="{{ $event->name }}" fetchpriority="high" class="evt-public-cover evt-inv-hero-cover evt-inv-pm-cover" width="1200" height="630">

        @if ($audioPath)
            <div class="evt-inv-audio-wrap">
                <button type="button" class="evt-inv-audio-play" data-inv-audio-play data-audio-src="{{ asset('storage/'.$audioPath) }}">
                    <i class="fa-solid fa-music" aria-hidden="true"></i>
                    <span class="evt-inv-audio-label">Play music</span>
                </button>
            </div>
        @endif
    </div>
</div>
