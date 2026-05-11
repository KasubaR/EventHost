@php
    $rsvpPublicAvailable = $rsvpPublicAvailable ?? false;
@endphp

<div class="evt-bg-rsvp-wrap">
    @if ($rsvpOpen)
        @if (! empty($isPreview))
            <div class="evt-rsvp-banner evt-rsvp-banner--open evt-bg-rsvp-banner">
                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                RSVP preview — publishing unlocks live links for your guests.
            </div>
        @elseif ($rsvpPublicAvailable && filled($event->slug ?? null))
            <div class="evt-bg-rsvp-banner evt-rsvp-actions evt-rsvp-actions--botanical">
                <a href="{{ route('rsvp.open.show', $event->slug) }}" class="btn-primary evt-rsvp-cta">Will you be attending?</a>
                <p class="evt-rsvp-helper">Respond in one step — no account needed.</p>
            </div>
        @else
            <div class="evt-rsvp-banner evt-rsvp-banner--open evt-bg-rsvp-banner">
                <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
                Your host will send you a personal RSVP link.
            </div>
        @endif
    @else
        <div class="evt-rsvp-banner evt-rsvp-banner--closed evt-bg-rsvp-banner">
            <i class="fa-solid fa-clock" aria-hidden="true"></i> The RSVP deadline has passed.
        </div>
    @endif

    <footer class="evt-bg-footer">
        <p class="footer-left">{{ $event->name }}</p>
        <p class="footer-right">{{ config('app.name') }} · {{ $event->event_date->format('Y') }}</p>
    </footer>
</div>
