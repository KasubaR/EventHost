@php
    $rsvpPublicAvailable = $rsvpPublicAvailable ?? false;
@endphp

@if ($rsvpOpen)
    @if (! empty($isPreview))
        <div class="evt-rsvp-banner evt-rsvp-banner--open">
            <i class="fa-solid fa-eye" aria-hidden="true"></i>
            RSVP preview — publishing unlocks live links for your guests.
        </div>
    @elseif ($rsvpPublicAvailable && filled($event->slug ?? null))
        <div class="evt-inline-rsvp" id="rsvp">
            <h2 class="evt-inline-rsvp-heading">RSVP</h2>
            <p class="evt-inline-rsvp-lead">Let the host know if you can make it — no account needed.</p>
            @include('rsvp.partials.open-rsvp-form', ['event' => $event, 'maxAttendees' => 1])
            <p class="evt-inline-rsvp-alt">
                <a href="{{ route('rsvp.open.show', $event->slug) }}" class="evt-inline-rsvp-alt-link">Prefer a dedicated RSVP page</a>
            </p>
        </div>
    @else
        <div class="evt-rsvp-banner evt-rsvp-banner--open">
            <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
            Your host will send you a personal RSVP link.
        </div>
    @endif
@else
    <div class="evt-rsvp-banner evt-rsvp-banner--closed">
        <i class="fa-solid fa-clock" aria-hidden="true"></i> The RSVP deadline has passed.
    </div>
@endif
