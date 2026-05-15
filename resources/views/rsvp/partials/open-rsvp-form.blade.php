@php
    $maxAttendees = $maxAttendees ?? 1;
@endphp

<form method="post" action="{{ route('rsvp.open.store', ['slug' => $event->slug]) }}" class="rsvp-form evt-open-rsvp-form">
    @csrf
    <div class="rsvp-field-group">
        <label class="rsvp-field-label" for="rsvp_name">Full name</label>
        <input id="rsvp_name" type="text" name="name" class="rsvp-input" required maxlength="255" value="{{ old('name') }}" autocomplete="name">
        @error('name')
            <p class="rsvp-field-error">{{ $message }}</p>
        @enderror
    </div>
    <div class="rsvp-field-group">
        <label class="rsvp-field-label" for="rsvp_email">Email</label>
        <input id="rsvp_email" type="email" name="email" class="rsvp-input" required maxlength="255" value="{{ old('email') }}" autocomplete="email">
        @error('email')
            <p class="rsvp-field-error">{{ $message }}</p>
        @enderror
    </div>
    <div class="rsvp-field-group">
        <label class="rsvp-field-label" for="rsvp_phone">Phone <span class="rsvp-optional">optional</span></label>
        <input id="rsvp_phone" type="tel" name="phone" class="rsvp-input" maxlength="50" value="{{ old('phone') }}" autocomplete="tel">
        @error('phone')
            <p class="rsvp-field-error">{{ $message }}</p>
        @enderror
    </div>

    @include('rsvp.partials.form-fields', [
        'maxAttendees'    => $maxAttendees,
        'existingRsvp'    => null,
        'rsvpFormConfig'  => $rsvpFormConfig ?? [],
    ])

    <button type="submit" class="btn-primary rsvp-submit">
        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send my response
    </button>
</form>
