{{--
    Personal-token counterpart to open-rsvp-form.blade.php. The guest is already
    identified by the token in the URL, so — unlike the open/public form — there
    are no name/email/phone fields to collect.
--}}
@php
    $maxAttendees = $maxAttendees ?? 1;
@endphp

<form method="post" action="{{ route('rsvp.token.store', ['token' => $guest->invitation_token]) }}" class="rsvp-form">
    @csrf
    @include('rsvp.partials.form-fields', [
        'maxAttendees'   => $maxAttendees,
        'existingRsvp'   => $existingRsvp ?? null,
        'rsvpFormConfig' => $rsvpFormConfig ?? [],
    ])
    <button type="submit" class="btn-primary rsvp-submit">
        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send my response
    </button>
</form>
