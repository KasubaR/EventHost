{{--
    Ticketed events replace the RSVP section with this "Buy tickets" panel in
    every layout variant — see section-loop.blade.php's rsvp case. Reuses the
    RSVP panel's own classes (.evt-inline-rsvp*) rather than new ones so it
    automatically inherits every skin's already-tuned per-layout styling
    (fonts, colors) instead of needing six new override files.
--}}
<div class="evt-inline-rsvp" id="tickets">
    <h2 class="evt-inline-rsvp-heading">Tickets</h2>

    @if (! empty($isPreview))
        <p class="evt-inline-rsvp-lead">Ticket sales preview — publishing and activation unlock the live buy flow.</p>
    @elseif ($event->ticketSalesAreApproved())
        <p class="evt-inline-rsvp-lead">Secure your spot — tickets are sold directly through EventHost.</p>
        <a href="{{ route('events.public.tickets', $event->slug) }}" class="btn-primary">
            <i class="fa-solid fa-ticket" aria-hidden="true"></i> Buy tickets
        </a>
    @else
        <p class="evt-inline-rsvp-lead">Ticket sales for this event haven't opened yet — check back soon.</p>
    @endif
</div>
