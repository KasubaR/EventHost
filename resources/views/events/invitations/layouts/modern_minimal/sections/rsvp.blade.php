@php
    $rsvpFormShown = \App\Support\InvitationRsvpState::formShown(
        (bool) ($rsvpOpen ?? false), isset($guest), ! empty($isPreview), (bool) ($rsvpPublicAvailable ?? false), filled($event->slug ?? null)
    );
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $footerNames = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;
    $footerLine = $footerNames.' · '.$event->event_date->format('Y');

    $rsvpNote = '';
    if ($event->rsvpDeadlineAt()) {
        $rsvpNote = 'Kindly respond by '.$event->rsvpDeadlineAt()->format('F j'.' \a\t g:i A T');
    }
@endphp

<section class="mm-section" id="rsvp">
    <h2 class="mm-section-title">Join Us</h2>
    @if ($rsvpNote !== '')
        <p class="mm-rsvp-lead">{{ $rsvpNote }}</p>
    @endif
    @if ($rsvpFormShown)
        <a href="#rsvp-form" class="mm-rsvp-cta">RSVP</a>
    @endif
    <div class="mm-rsvp-form-panel" id="rsvp-form">
        @include('events.invitations.sections.rsvp', ['rsvpWrapperHasId' => true])
    </div>
</section>

<footer class="mm-footer">{{ $footerLine }}</footer>
