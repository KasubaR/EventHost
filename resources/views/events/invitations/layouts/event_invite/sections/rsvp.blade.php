@php
    $rsvpFormShown = \App\Support\InvitationRsvpState::formShown(
        (bool) ($rsvpOpen ?? false), isset($guest), ! empty($isPreview), (bool) ($rsvpPublicAvailable ?? false), filled($event->slug ?? null)
    );
@endphp
@if ($rsvpFormShown)
    <div class="ei-rsvp-wrap">
        <a href="#rsvp" class="ei-rsvp-btn"><i class="fa-regular fa-envelope"></i> RSVP Now</a>
        <span class="ei-rsvp-note">Kindly confirm your attendance</span>
    </div>
@endif

<div class="ei-rsvp-panel" id="rsvp">
    @include('events.invitations.sections.rsvp', ['rsvpWrapperHasId' => true])
</div>
