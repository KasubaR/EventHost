@php
    use Illuminate\Support\Str;

    $videoPath = $invitation['effects']['video_background'] ?? null;
    $audioPath = $invitation['effects']['audio_track'] ?? null;

    $nameTrim = trim((string) $event->name);
    $nameLower = strtolower($nameTrim);
    $forPos = strpos($nameLower, ' for ');
    $titleBefore = $nameTrim;
    $titleAfter = '';
    if ($forPos !== false) {
        $titleBefore = trim(substr($nameTrim, 0, $forPos)) ?: $nameTrim;
        $titleAfter = trim(substr($nameTrim, $forPos + 4));
    }

    $descLines = preg_split('/\R/u', (string) $event->description, 2);
    $firstDesc = trim((string) ($descLines[0] ?? ''));
    $presenter = trim((string) ($invitation['content']['bfa_presenter_line'] ?? ''));
    if ($presenter === '') {
        $presenter = $firstDesc !== '' ? Str::limit($firstDesc, 140, '…') : (trim((string) $event->location_name) ?: 'You are warmly invited');
    }

    $presents = trim((string) ($invitation['content']['bfa_presents_line'] ?? ''));
    if ($presents === '') {
        $presents = 'Presents';
    }

    $typeLabels = [
        'wedding' => 'Wedding celebration',
        'birthday' => 'Birthday celebration',
        'graduation' => 'Graduation celebration',
        'corporate' => 'Corporate gathering',
        'baby_shower' => 'Baby shower',
        'funeral' => 'Memorial gathering',
        'church' => 'Conference gathering',
    ];
    $taglineBar = trim((string) ($invitation['content']['bfa_tagline_bar'] ?? ''));
    if ($taglineBar === '') {
        $taglineBar = $typeLabels[$event->event_type] ?? 'Special event';
    }

    $dateLine = $event->event_date->isoFormat('dddd, Do MMMM YYYY');
    $timeLine = '';
    if ($event->event_time) {
        $timeLine = \Carbon\Carbon::parse('2000-01-01 '.substr((string) $event->event_time, 0, 8))->format('H:i').' hrs';
    }
    $venueLine = trim((string) $event->location_name) !== '' ? trim((string) $event->location_name) : trim((string) $event->venue);
    if ($venueLine === '') {
        $venueLine = 'Details below';
    }

    $startsAt = \Carbon\Carbon::parse($event->event_date->format('Y-m-d').($event->event_time ? ' '.substr((string) $event->event_time, 0, 8) : ' 00:00:00'));
    $countdownLive = $invitation['effects']['countdown_enabled'] ?? true;
@endphp

<section class="bfa-hero" id="home">
    @if ($videoPath)
        <video
            class="bfa-hero-video"
            muted
            loop
            playsinline
            autoplay
            poster="{{ $event->cover_image_url }}"
            aria-hidden="true"
        >
            <source src="{{ asset('storage/'.$videoPath) }}" type="{{ str_ends_with(strtolower((string) $videoPath), '.webm') ? 'video/webm' : 'video/mp4' }}">
        </video>
        <div class="bfa-hero-video-scrim" aria-hidden="true"></div>
    @endif
    <div class="bfa-hero-bg" aria-hidden="true"></div>
    <div class="bfa-hero-particles" aria-hidden="true">
        @for ($i = 0; $i < 8; $i++)
            <div class="bfa-particle"></div>
        @endfor
    </div>

    <div class="bfa-hero-inner">
        <p class="bfa-hero-eyebrow"><strong>{{ $presenter }}</strong></p>
        <p class="bfa-hero-presents">{{ $presents }}</p>

        <h1 class="bfa-hero-title">
            @if ($titleAfter !== '')
                {{ $titleBefore }} <span class="bfa-hero-for">For</span> {{ $titleAfter }}
            @else
                {{ $titleBefore }}
            @endif
        </h1>

        <div class="bfa-hero-subbar">
            <span>{{ $taglineBar }}</span>
        </div>

        <div class="bfa-hero-meta">
            <div class="bfa-hero-meta-item">
                <span class="bfa-hero-meta-label">Date</span>
                <span class="bfa-hero-meta-value">{{ $dateLine }}</span>
            </div>
            <div class="bfa-hero-meta-sep" aria-hidden="true"></div>
            <div class="bfa-hero-meta-item">
                <span class="bfa-hero-meta-label">Time</span>
                <span class="bfa-hero-meta-value">{{ $timeLine !== '' ? $timeLine : 'TBA' }}</span>
            </div>
            <div class="bfa-hero-meta-sep" aria-hidden="true"></div>
            <div class="bfa-hero-meta-item">
                <span class="bfa-hero-meta-label">Venue</span>
                <span class="bfa-hero-meta-value">{{ Str::limit($venueLine, 36) }}</span>
            </div>
        </div>

        @if ($countdownLive)
            <div
                class="bfa-hero-countdown evt-inv-countdown"
                data-inv-countdown
                data-target="{{ $startsAt->toIso8601String() }}"
            >
                <p class="bfa-cd-eyebrow">Countdown</p>
                <div class="bfa-cd-grid evt-inv-countdown-grid" aria-live="polite">
                    <div class="bfa-cd-unit">
                        <span class="bfa-cd-value" data-inv-cd-days>0</span>
                        <span class="bfa-cd-label">Days</span>
                    </div>
                    <div class="bfa-cd-sep" aria-hidden="true"></div>
                    <div class="bfa-cd-unit">
                        <span class="bfa-cd-value" data-inv-cd-hours>0</span>
                        <span class="bfa-cd-label">Hrs</span>
                    </div>
                    <div class="bfa-cd-sep" aria-hidden="true"></div>
                    <div class="bfa-cd-unit">
                        <span class="bfa-cd-value" data-inv-cd-minutes>0</span>
                        <span class="bfa-cd-label">Min</span>
                    </div>
                    <div class="bfa-cd-sep" aria-hidden="true"></div>
                    <div class="bfa-cd-unit">
                        <span class="bfa-cd-value" data-inv-cd-seconds>0</span>
                        <span class="bfa-cd-label">Sec</span>
                    </div>
                </div>
                <p class="bfa-cd-done evt-inv-countdown-done evt-inv-countdown-done--hidden" data-inv-cd-done>This event has started.</p>
            </div>
        @else
            <div class="bfa-hero-countdown bfa-hero-countdown--static">
                <p class="bfa-cd-eyebrow">Save the date</p>
                <p class="bfa-cd-static">{{ $startsAt->timezone(config('app.timezone'))->format('l, F j, Y \a\t g:i A') }}</p>
            </div>
        @endif
    </div>

    @if ($audioPath)
        <div class="bfa-hero-audio">
            <button type="button" class="evt-inv-audio-play bfa-audio-btn" data-inv-audio-play data-audio-src="{{ asset('storage/'.$audioPath) }}">
                <i class="fa-solid fa-music" aria-hidden="true"></i>
                <span class="evt-inv-audio-label">Play music</span>
            </button>
        </div>
    @endif

    <div class="bfa-scroll-hint" aria-hidden="true">
        <span>Scroll</span>
        <div class="bfa-scroll-arrow"></div>
    </div>
</section>
