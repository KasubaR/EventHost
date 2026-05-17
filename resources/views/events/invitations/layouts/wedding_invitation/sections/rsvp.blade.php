@php
    $nameTrim = trim((string) $event->name);
    $footerNames = str_replace([' and ', ' And '], ' & ', $nameTrim);
    $footerNames = preg_replace('/\s*&\s*/', ' & ', $footerNames) ?? $footerNames;

    $footerQuote = trim((string) ($invitation['content']['wi_footer_quote'] ?? ''));
    if ($footerQuote === '') {
        $footerQuote = '"To love and to be loved is to feel the sun from both sides."';
    }

    $rsvpNote = '';
    if ($event->rsvp_deadline) {
        $rsvpNote = 'Please respond by '.$event->rsvp_deadline->format('jS F Y').' so we can make every detail perfect for your presence.';
    }
@endphp

<section class="wi-rsvp-section wi-reveal" data-wi-reveal>
    <p class="wi-section-tag">Kindly Reply</p>
    <h2 class="wi-section-title">Will you <em>join us?</em></h2>
    <div class="wi-orn" aria-hidden="true">— ◆ —</div>
    @if ($rsvpNote !== '')
        <p class="wi-section-body">{{ $rsvpNote }}</p>
    @endif

    @include('events.invitations.sections.rsvp')
</section>

<footer class="wi-footer">
    <div class="wi-footer-inner">
        <p class="wi-footer-names">{{ $footerNames }}</p>
        <p class="wi-footer-date">{{ $event->event_date->format('j') }} · {{ $event->event_date->format('F') }} · {{ $event->event_date->format('Y') }}</p>
        <hr class="wi-gold-rule" aria-hidden="true">
        <p class="wi-footer-note">{{ $footerQuote }}</p>
    </div>
</footer>
