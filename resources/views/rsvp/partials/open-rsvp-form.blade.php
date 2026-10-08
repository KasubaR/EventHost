@php
    $maxAttendees = $maxAttendees ?? 1;
    $phoneRequired = $phoneRequired ?? false;
    // Previews render against events that may be unsaved (no slug), so there is no
    // route to post to: the form shows every field but cannot submit.
    $previewOnly = $previewOnly ?? false;
@endphp

@if ($previewOnly)
<form class="rsvp-form evt-open-rsvp-form" data-rsvp-preview>
@else
<form method="post" action="{{ route('rsvp.open.store', ['slug' => $event->slug]) }}" class="rsvp-form evt-open-rsvp-form">
    @csrf
@endif
    <div class="rsvp-field-group">
        <label class="rsvp-field-label" for="rsvp_name">Full name</label>
        <input id="rsvp_name" type="text" name="name" class="rsvp-input" required maxlength="191" value="{{ old('name') }}" autocomplete="name">
        @error('name')
            <p class="rsvp-field-error">{{ $message }}</p>
        @enderror
    </div>
    <div class="rsvp-field-group">
        <label class="rsvp-field-label" for="rsvp_email">Email</label>
        <input id="rsvp_email" type="email" name="email" class="rsvp-input" required maxlength="191" value="{{ old('email') }}" autocomplete="email">
        @error('email')
            <p class="rsvp-field-error">{{ $message }}</p>
        @enderror
    </div>
    <div class="rsvp-field-group">
        @if ($phoneRequired)
            <label class="rsvp-field-label" for="rsvp_phone">Phone</label>
            <p class="rsvp-field-hint">So the host can reach you about this event, and send your invite link by WhatsApp where available.</p>
        @else
            <label class="rsvp-field-label" for="rsvp_phone">Phone <span class="rsvp-optional">optional</span></label>
        @endif
        <input id="rsvp_phone" type="tel" name="phone" class="rsvp-input" @required($phoneRequired) maxlength="50" value="{{ old('phone') }}" autocomplete="tel" placeholder="0971234567">
        @error('phone')
            <p class="rsvp-field-error">{{ $message }}</p>
        @enderror
    </div>

    @include('rsvp.partials.form-fields', [
        'maxAttendees'       => $maxAttendees,
        'existingRsvp'       => null,
        'rsvpFormConfig'     => $rsvpFormConfig ?? [],
        'preselectedStatus'  => $preselectedStatus ?? null,
    ])

    <button type="submit" class="btn-primary rsvp-submit" @disabled($previewOnly)>
        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send my response
    </button>
    @if ($previewOnly)
        <p class="rsvp-preview-note">Preview only. Responses aren't sent.</p>
    @endif
</form>
