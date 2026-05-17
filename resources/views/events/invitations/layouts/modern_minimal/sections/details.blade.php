@php
    $schedule = array_slice($invitation['content']['schedule'] ?? [], 0, 3);

    if (count($schedule) === 0) {
        $schedule = [
            [
                'title' => 'Ceremony',
                'time' => $event->event_time
                    ? \Carbon\Carbon::parse('2000-01-01 '.substr((string) $event->event_time, 0, 8))->format('g:i A')
                    : null,
                'detail' => trim((string) $event->venue) ?: trim((string) $event->location_name) ?: 'Details to follow',
            ],
        ];
    }
@endphp

@if (count($schedule) > 0)
    <section class="mm-section">
        <h2 class="mm-section-title">Details</h2>
        <div class="mm-details-row">
            @foreach ($schedule as $row)
                @php
                    $title = trim((string) ($row['title'] ?? ''));
                    $time = trim((string) ($row['time'] ?? ''));
                    $detail = trim((string) ($row['detail'] ?? ''));
                    $lines = array_values(array_filter([$time, $detail], fn ($v) => $v !== ''));
                @endphp
                @if ($title !== '' || $lines !== [])
                    <div class="mm-detail-block">
                        @if ($title !== '')
                            <h3>{{ $title }}</h3>
                        @endif
                        @if ($lines !== [])
                            <p>{!! implode('<br>', array_map('e', $lines)) !!}</p>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </section>
@endif
