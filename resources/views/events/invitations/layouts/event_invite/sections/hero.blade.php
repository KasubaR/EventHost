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
    $typeLabel = $typeLabels[$event->event_type] ?? ucfirst(str_replace('_', ' ', (string) $event->event_type));

    $hostName = trim((string) $event->name) !== '' ? trim((string) $event->name) : 'Your Celebration';

    $dayNum = (int) $event->event_date->format('j');
    $daySuffix = strtolower($event->event_date->format('S'));
    $dateRest = $event->event_date->format('F Y');

    $venueLine = trim((string) $event->venue);
    if ($venueLine === '') {
        $venueLine = trim((string) $event->location_name);
    }
    if ($venueLine === '') {
        $venueLine = '—';
    }

    $timeLine = '';
    if ($event->event_time) {
        $timeLine = \Carbon\Carbon::parse('2000-01-01 '.substr((string) $event->event_time, 0, 8))->format('H:i');
    }

    $colorTheme = trim((string) ($invitation['content']['ei_color_theme'] ?? ''));
    $guestSpeaker = trim((string) ($invitation['content']['ei_guest_speaker'] ?? ''));
    $mcLine = trim((string) ($invitation['content']['ei_mc'] ?? ''));

    $detailRows = [
        [
            'icon' => '📍',
            'label' => 'Venue & Time',
            'value' => $venueLine,
            'mono' => true,
            'time' => $timeLine,
            'show' => true,
        ],
        [
            'icon' => '🎨',
            'label' => 'Color Theme',
            'value' => $colorTheme,
            'show' => $colorTheme !== '',
        ],
        [
            'icon' => '🎤',
            'label' => 'Guest Speaker',
            'value' => $guestSpeaker,
            'show' => $guestSpeaker !== '',
        ],
        [
            'icon' => '👥',
            'label' => 'MC',
            'value' => $mcLine,
            'show' => $mcLine !== '',
        ],
    ];
@endphp

<div class="ei-bg-decor" aria-hidden="true">
    <div class="ei-feather ei-feather--1"></div>
    <div class="ei-feather ei-feather--2"></div>
    <div class="ei-feather ei-feather--3"></div>
    <div class="ei-feather ei-feather--4"></div>
    <div class="ei-feather ei-feather--5"></div>
</div>
<div class="ei-lights" data-ei-lights aria-hidden="true"></div>

<article class="ei-card">
    <p class="ei-join-label">Join Us For</p>

    <div class="ei-gold-line" aria-hidden="true">♡</div>

    <div class="ei-host-wrap">
        <span class="ei-left-heart" aria-hidden="true">♡</span>
        <h1 class="ei-host-name">{{ $hostName }}</h1>
        <div class="ei-hearts-decor" aria-hidden="true">
            <span>♡</span>
            <span class="ei-hearts-decor-small">♡</span>
        </div>
    </div>

    <p class="ei-event-type">{{ $typeLabel }}</p>

    <div class="ei-ornament" aria-hidden="true">— ❧ —</div>

    <p class="ei-on-the">On The</p>
    <p class="ei-date-block">{{ $dayNum }}<sup>{{ $daySuffix }}</sup> {{ $dateRest }}</p>

    <div class="ei-divider" aria-hidden="true">— ♡ —</div>

    <div class="ei-details">
        @foreach ($detailRows as $row)
            @if ($row['show'])
                <div class="ei-detail-row">
                    <div class="ei-icon-wrap" aria-hidden="true">{{ $row['icon'] }}</div>
                    <div class="ei-detail-text">
                        <span class="ei-detail-label">{{ $row['label'] }}</span>
                        <span class="ei-detail-value @if (! empty($row['mono'])) ei-detail-value--mono @endif">{{ $row['value'] }}</span>
                        @if (! empty($row['time']))
                            <span class="ei-detail-value ei-detail-value--mono ei-detail-value--time">{{ $row['time'] }}</span>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="ei-footer-ornament" aria-hidden="true">— ❧ ♡ ❧ —</div>
</article>
