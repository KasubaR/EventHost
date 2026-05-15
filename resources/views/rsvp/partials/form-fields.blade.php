@php
    use App\Enums\RsvpStatus;
    $maxAttendees = $maxAttendees ?? 1;
    $existing = $existingRsvp ?? null;
    $_rfc = $rsvpFormConfig ?? [];
    $_rfcLabel = fn(string $field, string $default): string =>
        (is_array($_rfc[$field] ?? null) && is_string($_rfc[$field]['label'] ?? null) && $_rfc[$field]['label'] !== '')
            ? $_rfc[$field]['label']
            : $default;
    $_rfcVisible = fn(string $field): bool =>
        ! is_array($_rfc[$field] ?? null) || (bool) ($_rfc[$field]['visible'] ?? true);
    $statusOld = old('status', $existing?->status?->value ?? RsvpStatus::Accepted->value);
    $countOld = old('attendee_count', $existing ? ($existing->status === \App\Enums\RsvpStatus::Accepted ? $existing->attendee_count : 0) : null);
    if ($countOld === null) {
        $countOld = 1;
    }
    $statusIcons = [
        RsvpStatus::Accepted->value => 'fa-solid fa-circle-check',
        RsvpStatus::Declined->value => 'fa-solid fa-circle-xmark',
        RsvpStatus::Maybe->value    => 'fa-regular fa-circle-question',
    ];
@endphp

<div class="rsvp-field-group">
    <span class="rsvp-field-label">Your response</span>
    <div class="rsvp-status-options">
        @foreach (RsvpStatus::cases() as $statusCase)
            <label class="rsvp-radio" data-status="{{ $statusCase->value }}">
                <span class="rsvp-radio-icon" aria-hidden="true">
                    <i class="{{ $statusIcons[$statusCase->value] }}"></i>
                </span>
                <input type="radio" name="status" value="{{ $statusCase->value }}" @checked($statusOld === $statusCase->value) required>
                <span>{{ $statusCase->label() }}</span>
            </label>
        @endforeach
    </div>
    @error('status')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
</div>

<div class="rsvp-field-group">
    <label class="rsvp-field-label" for="rsvp_attendee_count">Number attending <span class="rsvp-optional">confirm only</span></label>
    <select id="rsvp_attendee_count" name="attendee_count" class="rsvp-select" required>
        @for ($n = 0; $n <= $maxAttendees; $n++)
            <option value="{{ $n }}" @selected((int) $countOld === $n)>
                {{ $n === 0 ? '0 — not attending' : $n.' '.($n === 1 ? 'guest' : 'guests') }}
            </option>
        @endfor
    </select>
    @error('attendee_count')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
    @if ($maxAttendees > 1)
        <p class="rsvp-field-hint">Includes you plus any guests covered by your invitation.</p>
    @endif
</div>

@if ($_rfcVisible('message'))
<div class="rsvp-field-group">
    <label class="rsvp-field-label" for="rsvp_message">{{ $_rfcLabel('message', 'Message to host') }} <span class="rsvp-optional">optional</span></label>
    <textarea id="rsvp_message" name="message" class="rsvp-textarea" rows="3" maxlength="1000">{{ old('message', $existing?->message) }}</textarea>
    @error('message')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
</div>
@endif

@if ($_rfcVisible('meal_preference'))
<div class="rsvp-field-group">
    <label class="rsvp-field-label" for="rsvp_meal">{{ $_rfcLabel('meal_preference', 'Meal preference') }} <span class="rsvp-optional">optional</span></label>
    <input id="rsvp_meal" type="text" name="meal_preference" class="rsvp-input" maxlength="255" value="{{ old('meal_preference', $existing?->meal_preference) }}">
    @error('meal_preference')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
</div>
@endif

@if ($_rfcVisible('transportation_note'))
<div class="rsvp-field-group">
    <label class="rsvp-field-label" for="rsvp_transport">{{ $_rfcLabel('transportation_note', 'Transportation notes') }} <span class="rsvp-optional">optional</span></label>
    <input id="rsvp_transport" type="text" name="transportation_note" class="rsvp-input" maxlength="255" value="{{ old('transportation_note', $existing?->transportation_note) }}">
    @error('transportation_note')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
</div>
@endif

@if ($_rfcVisible('song_request'))
<div class="rsvp-field-group">
    <label class="rsvp-field-label" for="rsvp_song">{{ $_rfcLabel('song_request', 'Song request') }} <span class="rsvp-optional">optional</span></label>
    <input id="rsvp_song" type="text" name="song_request" class="rsvp-input" maxlength="255" value="{{ old('song_request', $existing?->song_request) }}">
    @error('song_request')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
</div>
@endif
