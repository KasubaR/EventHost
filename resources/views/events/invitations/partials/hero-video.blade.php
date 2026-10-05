{{-- Hero background video, shared by the standard, Pro Magazine and Beauty for Ashes heroes.

     Nothing heavy loads with the page. A video FILE has preload="none" and no autoplay attribute (autoplay would
     override preload); a YouTube background is an empty placeholder whose iframe invitation-public.js creates. The
     script starts either only on a normal connection (not Save-Data, not 2g) and when the guest has not asked for
     reduced motion; otherwise it offers a "Play video" button over the cover. With JavaScript off the cover (or the
     video's poster) shows, and a YouTube background offers a plain link. plans/invitation-page-resilience.md Phase 4.

     Expects: $event, $videoRaw, $videoEmbedSrc, $videoFilePath. Optional class hooks for layouts that style their own:
     $embedClass, $videoClass, $scrimClass. --}}
@php
    $embedClass = $embedClass ?? '';
    $videoClass = $videoClass ?? 'evt-inv-hero-video';
    $scrimClass = $scrimClass ?? 'evt-inv-hero-video-scrim';
    $watchUrl = $videoEmbedSrc ? \App\Support\InvitationVideoBackground::watchUrlFromStored($videoRaw) : null;
@endphp

@if ($videoEmbedSrc)
    <div class="evt-inv-hero-video-embed {{ $embedClass }}" aria-hidden="true" data-inv-video-embed data-embed-src="{{ $videoEmbedSrc }}"></div>
    <div class="{{ $scrimClass }}" aria-hidden="true"></div>
    @if ($watchUrl)
        <a class="evt-inv-video-link" href="{{ $watchUrl }}" target="_blank" rel="noopener noreferrer">
            <i class="fa-brands fa-youtube" aria-hidden="true"></i> Watch the video
        </a>
    @endif
@elseif ($videoFilePath)
    <video
        class="{{ $videoClass }}"
        muted
        loop
        playsinline
        preload="none"
        poster="{{ $event->cover_image_url }}"
        aria-hidden="true"
        data-inv-video
    >
        <source src="{{ asset('storage/'.$videoFilePath) }}" type="{{ str_ends_with(strtolower((string) $videoFilePath), '.webm') ? 'video/webm' : 'video/mp4' }}">
    </video>
    <div class="{{ $scrimClass }}" aria-hidden="true"></div>
@endif
