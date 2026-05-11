@php
    $startsAt = \Carbon\Carbon::parse($event->event_date->format('Y-m-d').' '.substr((string) $event->event_time, 0, 8), config('app.timezone'));
    $countdownLive = $invitation['effects']['countdown_enabled'] ?? true;
@endphp

@if ($countdownLive)
    <div
        class="evt-inv-countdown"
        data-inv-countdown
        data-target="{{ $startsAt->toIso8601String() }}"
    >
        <p class="evt-inv-countdown-heading">Starts in</p>
        <div class="evt-inv-countdown-grid" aria-live="polite">
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-days>0</span><span class="evt-inv-countdown-label">Days</span></div>
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-hours>0</span><span class="evt-inv-countdown-label">Hours</span></div>
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-minutes>0</span><span class="evt-inv-countdown-label">Minutes</span></div>
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-seconds>0</span><span class="evt-inv-countdown-label">Seconds</span></div>
        </div>
        <p class="evt-inv-countdown-done evt-inv-countdown-done--hidden" data-inv-cd-done>This event has started.</p>
    </div>
@else
    <div class="evt-inv-countdown evt-inv-countdown--static">
        <p class="evt-inv-countdown-heading">Event starts</p>
        <p class="evt-inv-countdown-static">{{ $startsAt->timezone(config('app.timezone'))->format('l, F j, Y \a\t g:i A') }}</p>
    </div>
@endif
