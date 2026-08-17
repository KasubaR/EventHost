{{--
    Tab strip for the host "Ticketing" section (Phase 15). Shared by every
    ticketing page — Overview, the Tickets table, Check-in, and Settings
    (ticket types + commission, still at events.ticket-types.index) all
    include this. Orders/Attendees/Sales/Revenue/Payouts have no page yet —
    rendered disabled rather than left out, so the section reads as the
    9-item list it was speced as, without inventing content for phases that
    haven't shipped (plans/ticketing.md, "don't build ahead of the phase
    that uses it").

    A bare <nav> picks up global.css's site-wide `nav {}` reset, so this
    needs its own override — same pattern as .dash-nav / nav.set-tabs /
    nav.legal-toc, see CLAUDE.md.
--}}
<nav class="tkt-tabs" aria-label="Ticketing">
    <a href="{{ route('events.tickets.overview', $event) }}" class="tkt-tab @if ($active === 'overview') tkt-tab--active @endif">
        <i class="fa-solid fa-gauge" aria-hidden="true"></i> Overview
    </a>
    <a href="{{ route('events.tickets.index', $event) }}" class="tkt-tab @if ($active === 'tickets') tkt-tab--active @endif">
        <i class="fa-solid fa-ticket" aria-hidden="true"></i> Tickets
    </a>
    <span class="tkt-tab tkt-tab--disabled" title="Coming soon">
        <i class="fa-solid fa-receipt" aria-hidden="true"></i> Orders
    </span>
    <span class="tkt-tab tkt-tab--disabled" title="Coming soon">
        <i class="fa-solid fa-users" aria-hidden="true"></i> Attendees
    </span>
    <a href="{{ route('events.tickets.checkin.scan', $event) }}" class="tkt-tab @if ($active === 'checkin') tkt-tab--active @endif">
        <i class="fa-solid fa-qrcode" aria-hidden="true"></i> Check-in
        @unless ($event->ownerHasPremiumEventTools())
            <span class="evt-credit-badge">Pro</span>
        @endunless
    </a>
    <span class="tkt-tab tkt-tab--disabled" title="Coming soon">
        <i class="fa-solid fa-chart-line" aria-hidden="true"></i> Sales
    </span>
    <span class="tkt-tab tkt-tab--disabled" title="Coming soon">
        <i class="fa-solid fa-sack-dollar" aria-hidden="true"></i> Revenue
    </span>
    <span class="tkt-tab tkt-tab--disabled" title="Coming soon">
        <i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i> Payouts
    </span>
    <a href="{{ route('events.ticket-types.index', $event) }}" class="tkt-tab @if ($active === 'settings') tkt-tab--active @endif">
        <i class="fa-solid fa-gear" aria-hidden="true"></i> Settings
    </a>
</nav>
