@php
    /** @var list<array{time: ?string, title: string, detail: ?string}> $schedule */
    $schedule = $invitation['content']['schedule'] ?? [];
@endphp

@if ($schedule !== [])
    <section class="bfa-section bfa-schedule">
        <div class="bfa-section-inner">
            <div class="bfa-section-label">Programme</div>
            <h2 class="bfa-section-heading">Schedule</h2>
            <ol class="bfa-schedule-list">
                @foreach ($schedule as $item)
                    <li class="bfa-schedule-item">
                        @if (! empty($item['time']))
                            <span class="bfa-schedule-time">{{ $item['time'] }}</span>
                        @endif
                        <span class="bfa-schedule-title">{{ $item['title'] }}</span>
                        @if (! empty($item['detail']))
                            <p class="bfa-schedule-detail">{{ $item['detail'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endif
