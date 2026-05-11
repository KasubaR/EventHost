@php
    $typeLabels = [
        'wedding' => 'Wedding',
        'birthday' => 'Birthday',
        'graduation' => 'Graduation',
        'corporate' => 'Corporate Event',
        'baby_shower' => 'Baby Shower',
        'funeral' => 'Memorial',
        'church' => 'Church Event',
    ];
    $typeLabel = $typeLabels[$event->event_type] ?? $event->event_type;
    $classYear = $event->event_date->format('Y');
    $nameTrim = trim((string) $event->name);
    $nameParts = preg_split('/\s+/u', $nameTrim, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $nameFirst = '';
    $nameLast = '';
    if (count($nameParts) >= 2) {
        $nameLast = array_pop($nameParts);
        $nameFirst = implode(' ', $nameParts);
    } else {
        $nameFirst = $nameTrim !== '' ? $nameTrim : 'Untitled Event';
    }
    $descFirstLine = null;
    if ($event->description) {
        $lines = preg_split('/\R/u', $event->description, 2);
        $descFirstLine = trim((string) ($lines[0] ?? ''));
        if (strlen($descFirstLine) > 160) {
            $descFirstLine = \Illuminate\Support\Str::limit($descFirstLine, 160, '…');
        }
    }

    $couplePaths = array_values(array_filter(array_map('strval', $invitation['media']['couple_photos'] ?? [])));
    $heroPortrait = $invitation['media']['hero_portrait'] ?? null;
    $heroPortrait = is_string($heroPortrait) && $heroPortrait !== '' ? $heroPortrait : null;
    $singleFrameSrc = $heroPortrait ? asset('storage/'.$heroPortrait) : $event->cover_image_url;
@endphp

<div class="evt-bg-nav-strip nav-strip">
    {{ $typeLabel }} celebration · Class of {{ $classYear }}
</div>

<div class="evt-bg-hero hero">
    <div class="hero-left">
        <p class="hero-tag">With joy &amp; pride</p>

        <h1 class="hero-name evt-bg-hero-title">
            @if ($nameLast !== '')
                {{ $nameFirst }}<br><span class="hero-name-italic">{{ $nameLast }}</span>
            @else
                {{ $nameFirst }}
            @endif
        </h1>

        <div class="hero-divider" aria-hidden="true">
            <div class="hero-divider-line"></div>
            <div class="hero-divider-circle"></div>
        </div>

        @if ($descFirstLine !== null && $descFirstLine !== '')
            <p class="hero-degree">{{ $descFirstLine }}</p>
        @endif

        @if ($event->venue)
            <p class="hero-school">{{ $event->venue }}</p>
        @elseif ($event->location_name)
            <p class="hero-school">{{ $event->location_name }}</p>
        @endif

        <div class="hero-date-badge">
            <span class="date-num">{{ $event->event_date->format('j') }}</span>
            <div class="date-text">
                <p class="date-month">{{ $event->event_date->format('F') }} · {{ $event->event_date->format('Y') }}</p>
                <p class="date-event">{{ $typeLabel }}
                    @if ($event->event_time)
                        · {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                    @endif
                </p>
            </div>
        </div>
    </div>

    <div class="hero-right">
        @include('events.invitations.layouts.botanical_graduation.partials.botanical-bg-svg')
        @if (count($couplePaths) >= 2)
            <div class="evt-bg-couple-frames" role="presentation">
                @foreach (array_slice($couplePaths, 0, 2) as $path)
                    <div class="photo-frame photo-frame--couple">
                        <img src="{{ asset('storage/'.$path) }}" alt="" class="evt-bg-frame-photo" width="260" height="340">
                    </div>
                @endforeach
            </div>
        @elseif (count($couplePaths) === 1)
            <div class="photo-frame">
                <img src="{{ asset('storage/'.$couplePaths[0]) }}" alt="" class="evt-bg-frame-photo" width="320" height="400">
            </div>
        @else
            <div class="photo-frame">
                <img src="{{ $singleFrameSrc }}" alt="" class="evt-bg-frame-photo" width="320" height="400">
            </div>
        @endif
    </div>
</div>
