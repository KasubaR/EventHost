@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $footerNames = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;

    $footerQuote = trim((string) ($invitation['content']['wi_footer_quote'] ?? ''));

    $rsvpNote = $event->rsvpDeadlineAt()
        ? 'Kindly respond by '.$event->rsvpDeadlineAt()->format('jS F Y').'.'
        : '';
@endphp

<section class="bw-section bw-rsvp">
    <p class="bw-tag">Kindly Reply</p>
    <h2 class="bw-title">Will you <em>join us?</em></h2>
    @if ($rsvpNote !== '')
        <p class="bw-body">{{ $rsvpNote }}</p>
    @endif

    @include('events.invitations.sections.rsvp')
</section>

<footer class="bw-footer">
    <div class="bw-orn" aria-hidden="true"><span></span></div>
    <p class="bw-footer-names">{{ $footerNames }}</p>
    <p class="bw-footer-date">{{ $event->event_date->format('j · F · Y') }}</p>
    @if ($footerQuote !== '')
        <p class="bw-footer-note">{{ $footerQuote }}</p>
    @endif
</footer>
