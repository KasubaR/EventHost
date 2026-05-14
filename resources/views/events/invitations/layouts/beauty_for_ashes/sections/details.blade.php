@php
    $venueNote = trim((string) ($invitation['content']['venue_note'] ?? ''));
    $theme = trim((string) ($invitation['content']['bfa_conference_theme'] ?? ''));
    $dress = trim((string) ($invitation['content']['bfa_dress_code'] ?? ''));
    $timeFmt = '';
    if ($event->event_time) {
        $timeFmt = \Carbon\Carbon::parse('2000-01-01 '.substr((string) $event->event_time, 0, 8))->format('H:i').' hrs';
    }
    $dateTimeLine = $event->event_date->format('l, jS F Y');
    if ($timeFmt !== '') {
        $dateTimeLine .= ' — '.$timeFmt;
    }
@endphp

<section class="bfa-section bfa-details" id="details">
    <div class="bfa-section-inner">
        <div class="bfa-section-label">Event information</div>
        <h2 class="bfa-section-heading">The details</h2>

        <div class="bfa-details-grid">
            <div class="bfa-details-cards">
                <div class="bfa-detail-card">
                    <div class="bfa-detail-icon" aria-hidden="true">&#128197;</div>
                    <div>
                        <div class="bfa-detail-label">Date &amp; time</div>
                        <div class="bfa-detail-value">{{ $dateTimeLine }}</div>
                    </div>
                </div>

                @if ($event->venue || $event->location_name)
                    <div class="bfa-detail-card">
                        <div class="bfa-detail-icon" aria-hidden="true">&#128205;</div>
                        <div>
                            <div class="bfa-detail-label">Venue</div>
                            <div class="bfa-detail-value">{{ $event->venue ?: $event->location_name }}</div>
                            @if ($venueNote !== '')
                                <div class="bfa-detail-note">{{ $venueNote }}</div>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($theme !== '')
                    <div class="bfa-detail-card">
                        <div class="bfa-detail-icon" aria-hidden="true">&#127774;</div>
                        <div>
                            <div class="bfa-detail-label">Theme</div>
                            <div class="bfa-detail-value">{{ $theme }}</div>
                        </div>
                    </div>
                @endif

                @if ($dress !== '')
                    <div class="bfa-detail-card">
                        <div class="bfa-detail-icon" aria-hidden="true">&#128141;</div>
                        <div>
                            <div class="bfa-detail-label">Dress code</div>
                            <div class="bfa-detail-value">{{ $dress }}</div>
                        </div>
                    </div>
                @endif

                @if ($event->latitude !== null && $event->longitude !== null)
                    <div class="bfa-detail-card">
                        <div class="bfa-detail-icon" aria-hidden="true">&#128506;</div>
                        <div>
                            <div class="bfa-detail-label">Directions</div>
                            <div class="bfa-detail-value">
                                <a class="bfa-inline-link" href="https://www.google.com/maps?q={{ $event->latitude }},{{ $event->longitude }}" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <div class="bfa-details-aside">
                <div class="bfa-theme-panel">
                    @if ($theme !== '')
                        <div class="bfa-theme-row">
                            <span class="bfa-theme-dot" aria-hidden="true"></span>
                            <span class="bfa-theme-label">Theme</span>
                            <span class="bfa-theme-value">{{ $theme }}</span>
                        </div>
                    @endif
                    @if ($dress !== '')
                        <div class="bfa-theme-row">
                            <span class="bfa-theme-dot" aria-hidden="true"></span>
                            <span class="bfa-theme-label">Dress code</span>
                            <span class="bfa-theme-value">{{ $dress }}</span>
                        </div>
                    @endif
                    <div class="bfa-theme-row">
                        <span class="bfa-theme-dot" aria-hidden="true"></span>
                        <span class="bfa-theme-label">Guests</span>
                        <span class="bfa-theme-value">
                            @if ($event->guest_limit)
                                Guest limit {{ $event->guest_limit }}
                            @else
                                Open attendance
                            @endif
                            @if ($event->allow_plus_one)
                                · Plus-ones welcome
                            @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
