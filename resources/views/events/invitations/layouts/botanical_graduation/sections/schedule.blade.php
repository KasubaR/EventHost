@php
    $schedule = $invitation['content']['schedule'] ?? [];
@endphp

@if ($schedule !== [])
    <section class="stats-band evt-bg-stats-band evt-inv-schedule evt-inv-schedule--botanical" aria-label="Event schedule">
        <div class="evt-bg-countdown-shell">
            <p class="evt-bg-countdown-eyebrow">The day</p>
            <p class="evt-bg-countdown-title">Schedule</p>
            <ol class="evt-inv-schedule-list evt-inv-schedule-list--botanical">
                @foreach ($schedule as $item)
                    <li class="evt-inv-schedule-item evt-inv-schedule-item--botanical">
                        @if (! empty($item['time']))
                            <span class="evt-inv-schedule-time">{{ $item['time'] }}</span>
                        @endif
                        <span class="evt-inv-schedule-title">{{ $item['title'] }}</span>
                        @if (! empty($item['detail']))
                            <p class="evt-inv-schedule-detail">{{ $item['detail'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endif
