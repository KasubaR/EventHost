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
    // A guest with no answer on file starts with nothing chosen: a pre-ticked "Attending" turned a
    // distracted tap on Send into a silent yes (and a taken seat). The radios are `required`, so the
    // browser asks. A returning guest sees their stored answer; a ?status= deep link keeps its choice.
    $statusOld = old('status', $existing?->status?->value ?? $preselected);
    $countOld = old('attendee_count', $existing ? ($existing->status === RsvpStatus::Accepted ? $existing->attendee_count : 0) : null);
    if ($countOld === null) {
        // Unchosen counts as 1, not 0: without JavaScript nothing syncs the count to the answer, and an
        // attending guest posting 0 would be rejected. Declined/Maybe ignore the number anyway.
        $countOld = in_array($statusOld, [RsvpStatus::Declined->value, RsvpStatus::Maybe->value], true) ? 0 : 1;
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
                {{-- A plus-one is the second seat, so say that instead of a bare count. --}}
                {{ match (true) {
                    $n === 0 => 'Not attending',
                    $n === 1 => 'Just me',
                    $n === 2 => 'Me + 1 guest',
                    default => $n.' guests',
                } }}
            </option>
        @endfor
    </select>
    @error('attendee_count')
        <p class="rsvp-field-error">{{ $message }}</p>
    @enderror
    <p class="rsvp-field-hint">@if ($maxAttendees > 1)"Me + 1 guest" brings one person with you. @endif Only counted when you are attending.</p>
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
