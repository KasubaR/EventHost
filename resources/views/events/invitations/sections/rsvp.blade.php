@php
    $rsvpPublicAvailable = $rsvpPublicAvailable ?? false;
@endphp

@if ($rsvpOpen)
    @if (! empty($isPreview))
        <div class="evt-rsvp-banner evt-rsvp-banner--open">
            <i class="fa-solid fa-eye" aria-hidden="true"></i>
            RSVP preview — publishing unlocks live links for your guests.
        </div>
    @elseif (isset($guest))
        {{-- Personal token link (rsvp.token.show) — always the guest's own form here,
             regardless of $rsvpPublicAvailable/slug: those only govern the public/open
             flow, and a guest reading this already holds a valid personal invite. --}}
        <div class="evt-inline-rsvp" id="rsvp">
            <h2 class="evt-inline-rsvp-heading">RSVP</h2>
            <p class="evt-inline-rsvp-lead">Hi {{ $guest->name }}, let the host know if you can make it.</p>
            @include('rsvp.partials.entry-pass', ['guest' => $guest, 'showEntryPass' => $showEntryPass ?? false])
            @include('rsvp.partials.token-rsvp-form', [
                'guest'          => $guest,
                'maxAttendees'   => $maxAttendees ?? 1,
                'existingRsvp'   => $existingRsvp ?? null,
                'rsvpFormConfig' => $invitation['rsvp_form'] ?? [],
            ])
        </div>
    @elseif ($rsvpPublicAvailable && filled($event->slug ?? null))
        <div class="evt-inline-rsvp" id="rsvp">
            <h2 class="evt-inline-rsvp-heading">RSVP</h2>
            <p class="evt-inline-rsvp-lead">Let the host know if you can make it — no account needed.</p>
            @include('rsvp.partials.open-rsvp-form', [
                'event'          => $event,
                'maxAttendees'   => 1,
                'rsvpFormConfig' => $invitation['rsvp_form'] ?? [],
            ])
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
@elseif ($event->isLocked())
    <div class="evt-rsvp-banner evt-rsvp-banner--closed">
        <i class="fa-solid fa-champagne-glasses" aria-hidden="true"></i>
        This event has already taken place. Thank you to everyone who came.
    </div>
@else
    <div class="evt-rsvp-banner evt-rsvp-banner--closed">
        <i class="fa-solid fa-clock" aria-hidden="true"></i> The RSVP deadline has passed.
    </div>
@endif
