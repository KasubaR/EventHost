<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionTier;
use App\Models\Event;
use App\Rules\EventSlugAvailable;
use App\Support\BillingPlan;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only normalizes a field that was actually submitted — an empty string
     * (a cleared text input) becomes null so 'nullable' rules like 'numeric'
     * or 'date' treat it as absent rather than failing on ''. A field that
     * was not submitted at all is left alone: Validator::validated() only
     * includes a key when it exists in the request's data array, so merging
     * a null in for every key regardless of whether the client sent it would
     * make a genuinely partial payload (anything other than the edit page's
     * own form, which always resubmits every field together) silently wipe
     * venue/location/rsvp_deadline/etc. back to null via fill() — the field
     * was never "sometimes" in practice, prepareForValidation() was forcing
     * it to always be present.
     */
    protected function prepareForValidation(): void
    {
        $updates = [];

        foreach (['latitude', 'longitude', 'guest_limit', 'description', 'venue', 'location_name', 'rsvp_deadline'] as $key) {
            if ($this->has($key) && $this->input($key) === '') {
                $updates[$key] = null;
            }
        }

        $updates['slug'] = $this->normalizeSlugInput($this->input('slug'));

        $this->merge($updates);
    }

    private function normalizeSlugInput(mixed $slug): ?string
    {
        if (! is_string($slug)) {
            return null;
        }

        $slug = strtolower(trim($slug));

        return $slug === '' ? null : $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // product_kind is immutable and not part of this payload, so derive
        // the allowed options from the event being edited — and grandfather
        // its current value in, so an event whose stored type predates this
        // kind split (or a since-changed list) can still be saved without
        // being forced to change Event type first. See Event::eventTypesFor().
        $event = $this->route('event');
        $eventTypes = Event::eventTypesFor($event?->product_kind, $event?->event_type);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'event_type' => ['sometimes', 'required', Rule::in($eventTypes)],
            'description' => ['nullable', 'string', 'max:20000'],
            'event_date' => ['sometimes', 'required', 'date'],
            'event_time' => ['sometimes', 'required', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'venue' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:4096'],

            // Receipt for a cover already uploaded from the edit page — see
            // EventInvitationMediaController. Stripped before fill(); it is not a column.
            'staged_media' => ['nullable', 'array', 'max:4'],
            'staged_media.*' => ['integer', 'min:1'],
            'slug' => ['nullable', 'string', new EventSlugAvailable($event?->id)],
            // event_date comparisons live in withValidator() below — a bare
            // 'before_or_equal:event_date' compares against event_date parsed
            // as midnight, which would reject any same-day deadline that has
            // a time on it at all (i.e. almost every real deadline).
            'rsvp_deadline' => ['nullable', 'date'],
            'guest_limit' => $this->guestLimitRules(),
            'allow_plus_one' => ['boolean'],
            'show_guest_list' => ['boolean'],
            'photo_wall_enabled' => ['boolean'],
            'photo_wall_requires_approval' => ['boolean'],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function guestLimitRules(): array
    {
        $rules = ['nullable', 'integer', 'min:1'];

        // Ticketed events ignore guest_limit on save; still validate against
        // the owner's invitation-plan capacity when the field is present.
        $event = $this->route('event');
        if ($event instanceof Event && $event->isTicketed()) {
            $rules[] = 'max:100000';

            return $rules;
        }

        $capacity = BillingPlan::guestLimitDefaultForTier(
            $this->user()?->subscriptionTier() ?? SubscriptionTier::None
        );

        if ($capacity !== null) {
            $rules[] = 'max:'.$capacity;
        } else {
            $rules[] = 'max:100000';
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardRsvpDeadline($validator);
            $this->guardEventNotPushedIntoPast($validator);
            $this->guardCustomSlugChoice($validator);
        });
    }

    /**
     * Custom vanity slugs are Pro and above. Blank/null keeps the current
     * slug (EventSlugService::apply no-op). Submitting the event's own
     * current slug is also fine — a Pro→Base downgrade or a stuck form
     * value must not block ordinary saves. Only a change is refused.
     */
    private function guardCustomSlugChoice(Validator $validator): void
    {
        $slug = $this->input('slug');

        if ($slug === null || $slug === '') {
            return;
        }

        if ($this->user()?->canChooseCustomEventSlug()) {
            return;
        }

        $event = $this->route('event');

        if ($event instanceof Event && $event->slug === $slug) {
            return;
        }

        $validator->errors()->add('slug', 'Choosing a custom URL requires the Pro plan.');
    }

    /**
     * Falls back to the event's current stored value for whichever of
     * event_date/event_time is not part of this payload — 'sometimes' means
     * either can be legitimately absent on a partial update.
     */
    private function combinedEventInstant(Event $event): ?Carbon
    {
        $date = $this->has('event_date') ? $this->input('event_date') : $event->event_date?->format('Y-m-d');
        $time = $this->has('event_time') ? $this->input('event_time') : $event->event_time;

        if (! is_string($date) || $date === '' || ! is_string($time) || $time === '') {
            return null;
        }

        try {
            return Carbon::parse($date.' '.$time);
        } catch (\Throwable) {
            return null;
        }
    }

    private function guardRsvpDeadline(Validator $validator): void
    {
        $event = $this->route('event');

        if (! $event instanceof Event) {
            return;
        }

        // Falls back to the stored value when rsvp_deadline is not part of
        // this payload, same reasoning as combinedEventInstant() below — a
        // partial update that only touches event_date/event_time must still
        // be checked against whatever deadline is already on the event.
        $deadlineRaw = $this->has('rsvp_deadline')
            ? $this->input('rsvp_deadline')
            : $event->rsvp_deadline?->toDateTimeString();

        if (! is_string($deadlineRaw) || $deadlineRaw === '') {
            return;
        }

        $eventInstant = $this->combinedEventInstant($event);

        if ($eventInstant === null) {
            return;
        }

        try {
            $deadline = Carbon::parse($deadlineRaw);
        } catch (\Throwable) {
            return;
        }

        if ($deadline->greaterThan($eventInstant)) {
            $validator->errors()->add('rsvp_deadline', 'The RSVP deadline must be before the event starts.');
        }
    }

    /**
     * An already-past event is allowed to have its date corrected
     * retroactively — that path is metered by EventCreditService's redefine
     * charge (see Event::isLocked()), not blocked here. This only stops a
     * currently-upcoming event from being moved into the past (or, if it's
     * dated today, having its time changed to one that has already passed),
     * which today nothing prevents.
     */
    private function guardEventNotPushedIntoPast(Validator $validator): void
    {
        $event = $this->route('event');

        if (! $event instanceof Event || $event->isLocked()) {
            return;
        }

        $eventInstant = $this->combinedEventInstant($event);

        if ($eventInstant !== null && $eventInstant->lessThan(now()->subMinutes(5))) {
            $validator->errors()->add('event_date', 'An upcoming event cannot be moved into the past.');
        }
    }
}
