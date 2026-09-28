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
    $preselected = ($preselectedStatus ?? null) instanceof RsvpStatus ? $preselectedStatus->value : null;
    $statusOld = old('status', $existing?->status?->value ?? $preselected ?? RsvpStatus::Accepted->value);
    $countOld = old('attendee_count', $existing ? ($existing->status === RsvpStatus::Accepted ? $existing->attendee_count : 0) : null);
    if ($countOld === null) {
        $countOld = $statusOld === RsvpStatus::Accepted->value ? 1 : 0;
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

@once
    @push('scripts')
        <script src="{{ asset('js/rsvp-form.js') }}" defer></script>
    @endpush
@endonce
