@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $footerNames = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;
@endphp

<section class="mg-section" id="rsvp">
    <div class="mg-wrap mg-wrap--form">
        <h2 class="mg-title">RSVP</h2>
        @if ($event->rsvpDeadlineAt())
            <p class="mg-rsvp-lead">Kindly respond by {{ $event->rsvpDeadlineAt()->format('F jS'.' \a\t g:i A T') }}</p>
        @endif
        <div class="mg-rsvp-panel">
            @include('events.invitations.sections.rsvp', ['rsvpWrapperHasId' => true])
        </div>
    </div>
</section>

<footer class="mg-footer">
    <p class="mg-footer-names">{{ $footerNames }}</p>
    <p class="mg-footer-date">{{ $event->event_date->format('d.m.Y') }}</p>
</footer>
