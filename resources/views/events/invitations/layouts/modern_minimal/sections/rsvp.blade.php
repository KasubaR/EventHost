@php
    $nameTrim = trim((string) $event->name);
    $footerLine = preg_replace('/\s*&\s*/', ' & ', $nameTrim).' — '.$event->event_date->format('Y');

    $rsvpNote = '';
    if ($event->rsvp_deadline) {
        $rsvpNote = 'Kindly respond by '.$event->rsvp_deadline->format('F j');
    }
@endphp

<section class="mm-section" id="rsvp">
    <h2 class="mm-section-title">Join Us</h2>
    @if ($rsvpNote !== '')
        <p class="mm-rsvp-lead">{{ $rsvpNote }}</p>
    @endif
    <a href="#rsvp-form" class="mm-rsvp-cta">RSVP</a>
    <div class="mm-rsvp-form-panel" id="rsvp-form">
        @include('events.invitations.sections.rsvp')
    </div>
</section>

<footer class="mm-footer">{{ $footerLine }}</footer>
