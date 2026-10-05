@php
    // Venue wall-clock, not UTC: the ticker's target is an instant, so UTC would run it two hours late.
    $startsAt = $event->startsAt();
    $cd = \App\Support\InvitationCountdown::parts($startsAt);
    $countdownLive = $invitation['effects']['countdown_enabled'] ?? true;
@endphp

@if ($countdownLive)
    <div
        class="stats-band evt-bg-stats-band evt-inv-countdown evt-bg-countdown-band"
        data-inv-countdown
        data-target="{{ $startsAt->toIso8601String() }}"
    >
        <div class="evt-bg-countdown-shell">
            <p class="evt-bg-countdown-eyebrow">Countdown</p>
            <p class="evt-bg-countdown-title">Until we celebrate</p>
            <div class="evt-inv-countdown-grid evt-bg-stats-grid" aria-live="polite">
                <div class="evt-bg-countdown-unit">
                    <div class="evt-bg-countdown-ring">
                        <svg class="evt-bg-countdown-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                            <g class="evt-bg-countdown-svg-rot" transform="rotate(-90 50 50)">
                                <circle class="evt-bg-countdown-progress" data-inv-cd-ring="days" cx="50" cy="50" r="43" fill="none"></circle>
                            </g>
                        </svg>
                        <span class="stat-num evt-bg-countdown-value" data-inv-cd-days>{{ $cd['days'] }}</span>
                    </div>
                    <span class="stat-label">Days</span>
                </div>
                <div class="evt-bg-countdown-unit">
                    <div class="evt-bg-countdown-ring">
                        <svg class="evt-bg-countdown-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                            <g class="evt-bg-countdown-svg-rot" transform="rotate(-90 50 50)">
                                <circle class="evt-bg-countdown-progress" data-inv-cd-ring="hours" cx="50" cy="50" r="43" fill="none"></circle>
                            </g>
                        </svg>
                        <span class="stat-num evt-bg-countdown-value" data-inv-cd-hours>{{ $cd['hours'] }}</span>
                    </div>
                    <span class="stat-label">Hours</span>
                </div>
                <div class="evt-bg-countdown-unit">
                    <div class="evt-bg-countdown-ring">
                        <svg class="evt-bg-countdown-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                            <g class="evt-bg-countdown-svg-rot" transform="rotate(-90 50 50)">
                                <circle class="evt-bg-countdown-progress" data-inv-cd-ring="minutes" cx="50" cy="50" r="43" fill="none"></circle>
                            </g>
                        </svg>
                        <span class="stat-num evt-bg-countdown-value" data-inv-cd-minutes>{{ $cd['minutes'] }}</span>
                    </div>
                    <span class="stat-label">Minutes</span>
                </div>
                <div class="evt-bg-countdown-unit">
                    <div class="evt-bg-countdown-ring">
                        <svg class="evt-bg-countdown-svg" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
                            <g class="evt-bg-countdown-svg-rot" transform="rotate(-90 50 50)">
                                <circle class="evt-bg-countdown-progress" data-inv-cd-ring="seconds" cx="50" cy="50" r="43" fill="none"></circle>
                            </g>
                        </svg>
                        <span class="stat-num evt-bg-countdown-value" data-inv-cd-seconds>{{ $cd['seconds'] }}</span>
                    </div>
                    <span class="stat-label">Seconds</span>
                </div>
            </div>
        </div>
        <p class="evt-inv-countdown-nojs">Starts {{ \App\Support\InvitationCountdown::staticLine($startsAt) }}</p>
        <p class="evt-inv-countdown-done evt-inv-countdown-done--hidden evt-bg-countdown-done" data-inv-cd-done>This event has started.</p>
    </div>
@else
    <div class="stats-band evt-bg-stats-band evt-inv-countdown evt-inv-countdown--static evt-bg-countdown-band">
        <div class="evt-bg-countdown-shell evt-bg-countdown-shell--static">
            <p class="evt-bg-countdown-eyebrow">Save the date</p>
            <p class="evt-bg-countdown-title">Event starts</p>
            <p class="stat-num evt-inv-countdown-static">{{ \App\Support\InvitationCountdown::staticLine($startsAt) }}</p>
        </div>
    </div>
@endif
