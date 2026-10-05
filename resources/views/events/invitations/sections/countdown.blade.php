@php
    // Venue wall-clock, not UTC: the ticker's target is an instant, so UTC would run it two hours late.
    $startsAt = $event->startsAt();
    $cd = \App\Support\InvitationCountdown::parts($startsAt);
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
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-days>{{ $cd['days'] }}</span><span class="evt-inv-countdown-label">Days</span></div>
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-hours>{{ $cd['hours'] }}</span><span class="evt-inv-countdown-label">Hours</span></div>
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-minutes>{{ $cd['minutes'] }}</span><span class="evt-inv-countdown-label">Minutes</span></div>
            <div class="evt-inv-countdown-unit"><span class="evt-inv-countdown-value" data-inv-cd-seconds>{{ $cd['seconds'] }}</span><span class="evt-inv-countdown-label">Seconds</span></div>
        </div>
        <p class="evt-inv-countdown-nojs">Starts {{ \App\Support\InvitationCountdown::staticLine($startsAt) }}</p>
        <p class="evt-inv-countdown-done evt-inv-countdown-done--hidden" data-inv-cd-done>This event has started.</p>
    </div>
@else
    <div class="evt-inv-countdown evt-inv-countdown--static">
        <p class="evt-inv-countdown-heading">Event starts</p>
        <p class="evt-inv-countdown-static">{{ \App\Support\InvitationCountdown::staticLine($startsAt) }}</p>
    </div>
@endif
