@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $footerNames = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;
@endphp

<section class="db-section" id="rsvp">
    <div class="db-wrap db-wrap--form">
        <h2 class="db-title">RSVP</h2>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')
        @if ($event->rsvpDeadlineAt())
            <p class="db-rsvp-lead">Kindly respond by {{ $event->rsvpDeadlineAt()->format('F jS') }}</p>
        @endif
        <div class="db-panel db-rsvp-panel">
            @include('events.invitations.sections.rsvp', ['rsvpWrapperHasId' => true])
        </div>
    </div>
</section>

<footer class="db-footer">
    <p class="db-footer-names db-script">{{ $footerNames }}</p>
    <p class="db-footer-date">{{ $event->event_date->format('d.m.Y') }}</p>
    @include('events.invitations.layouts.wedding_dusty_blue.partials.heart', ['class' => 'db-heart--block'])
</footer>
