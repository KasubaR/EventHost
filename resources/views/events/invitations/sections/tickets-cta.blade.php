{{--
    Ticketed events replace the RSVP section with this "Buy tickets" panel in
    every layout variant — see partials/section.blade.php's rsvp case. Reuses the
    RSVP panel's own classes (.evt-inline-rsvp*) rather than new ones so it
    automatically inherits every skin's already-tuned per-layout styling
    (fonts, colors) instead of needing six new override files.
--}}
<div class="evt-inline-rsvp" id="tickets">
    <h2 class="evt-inline-rsvp-heading">Tickets</h2>

    @if (! empty($isPreview))
        <p class="evt-inline-rsvp-lead">Ticket sales preview. Publishing and activation unlock the live buy flow.</p>
    @elseif ($event->ticketSalesAreApproved())
        <p class="evt-inline-rsvp-lead">Secure your spot. Tickets are sold directly through EventHost.</p>
        <a href="{{ route('events.public.tickets', $event->slug) }}" class="btn-primary">
            <x-ticket-icon /> Buy tickets
        </a>
    @else
        <p class="evt-inline-rsvp-lead">Ticket sales for this event haven't opened yet. Check back soon.</p>
    @endif
</div>
