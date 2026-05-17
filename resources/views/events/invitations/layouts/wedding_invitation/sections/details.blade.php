@php
    $schedule = array_slice($invitation['content']['schedule'] ?? [], 0, 3);
    $icons = ['💍', '🌿', '🌸'];

    if (count($schedule) === 0) {
        $schedule = [
            [
                'title' => 'Ceremony',
                'detail' => $event->venue ?: ($event->location_name ?: 'Venue to be announced'),
                'time' => $event->event_time
                    ? \Carbon\Carbon::parse('2000-01-01 '.substr((string) $event->event_time, 0, 8))->format('g:i A')
                    : null,
            ],
        ];
        if ($event->venue && $event->location_name && $event->venue !== $event->location_name) {
            $schedule[] = [
                'title' => 'Location',
                'detail' => $event->location_name,
                'time' => null,
            ];
        }
    }

    $coverUrl = $event->cover_image_url;
@endphp

@if (count($schedule) > 0)
    <section
        class="wi-details-section wi-reveal"
        data-wi-reveal
        @if ($coverUrl) style="--wi-details-bg: url('{{ e($coverUrl) }}');" @endif
    >
        <div class="wi-details-inner">
            <p class="wi-section-tag">Event Details</p>
            <h2 class="wi-section-title">The <em>Celebration</em></h2>
            <div class="wi-orn" aria-hidden="true">— ◆ —</div>
            <div class="wi-details-grid">
                @foreach ($schedule as $idx => $row)
                    @php
                        $label = trim((string) ($row['title'] ?? ''));
                        $value = trim((string) ($row['detail'] ?? ''));
                        $sub = trim((string) ($row['time'] ?? ''));
                        if ($value === '' && $label !== '') {
                            $value = $label;
                            $label = 'Details';
                        }
                    @endphp
                    @if ($label !== '' || $value !== '')
                        <div class="wi-detail-card">
                            <div class="wi-detail-icon" aria-hidden="true">{{ $icons[$idx] ?? '✦' }}</div>
                            @if ($label !== '')
                                <p class="wi-detail-label">{{ $label }}</p>
                            @endif
                            @if ($value !== '')
                                <p class="wi-detail-value">{{ $value }}</p>
                            @endif
                            @if ($sub !== '')
                                <p class="wi-detail-sub">{{ $sub }}</p>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </section>
@endif
