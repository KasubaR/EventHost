@php
    /** @var list<array{time: ?string, title: string, detail: ?string}> $schedule */
    $schedule = $invitation['content']['schedule'] ?? [];
@endphp

@if ($schedule !== [])
    <section class="evt-inv-section evt-inv-schedule">
        <h2 class="evt-inv-schedule-heading">Schedule</h2>
        <ol class="evt-inv-schedule-list">
            @foreach ($schedule as $item)
                <li class="evt-inv-schedule-item">
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
    </section>
@endif
