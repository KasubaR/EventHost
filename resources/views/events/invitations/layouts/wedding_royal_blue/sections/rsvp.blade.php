@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $footerNames = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;
@endphp

<section class="rb-section rb-section--tint" id="rsvp">
    <div class="rb-wrap rb-wrap--form">
        <h2 class="rb-title">Join Our Celebration</h2>
        @include('events.invitations.layouts.wedding_royal_blue.partials.ornament')
        @if ($event->rsvpDeadlineAt())
            <p class="rb-rsvp-lead">Kindly respond by {{ $event->rsvpDeadlineAt()->format('F jS'.' \a\t g:i A T') }}</p>
        @endif
        <div class="rb-rsvp-panel">
            @include('events.invitations.sections.rsvp', ['rsvpWrapperHasId' => true])
        </div>
    </div>
</section>

<footer class="rb-footer">
    <p class="rb-footer-names">{{ $footerNames }}</p>
    <p class="rb-footer-date">{{ $event->event_date->format('d.m.Y') }}</p>
</footer>
