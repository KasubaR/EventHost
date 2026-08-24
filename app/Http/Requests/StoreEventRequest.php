<?php

namespace App\Http\Requests;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Rules\EventSlugAvailable;
use App\Rules\UserCanUseInvitationTemplate;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'preferred_invitation_template_id' => $this->input('preferred_invitation_template_id') === '' || $this->input('preferred_invitation_template_id') === null ? null : $this->input('preferred_invitation_template_id'),
            'latitude' => $this->latitude === '' || $this->latitude === null ? null : $this->latitude,
            'longitude' => $this->longitude === '' || $this->longitude === null ? null : $this->longitude,
            'guest_limit' => $this->guest_limit === '' || $this->guest_limit === null ? null : $this->guest_limit,
            'description' => $this->description === '' ? null : $this->description,
            'venue' => $this->venue === '' ? null : $this->venue,
            'location_name' => $this->location_name === '' ? null : $this->location_name,
            'rsvp_deadline' => $this->rsvp_deadline === '' ? null : $this->rsvp_deadline,
            'slug' => $this->normalizeSlugInput($this->input('slug')),
        ]);
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
        // Options differ by product kind (ticketed events get commercial
        // types like Concert/Conference instead of Wedding/Baby Shower) — see
        // Event::eventTypesFor(). Falls back to the full union when
        // product_kind itself is missing/invalid, so that error surfaces on
        // its own field instead of a confusing second one on event_type.
        $productKind = EventProductKind::tryFrom((string) $this->input('product_kind'));

        return [
            'preferred_invitation_template_id' => [
                'nullable',
                Rule::exists('invitation_templates', 'id')->where('is_active', true),
                new UserCanUseInvitationTemplate,
            ],
            'name' => ['required', 'string', 'max:255'],
            'event_type' => ['required', Rule::in(Event::eventTypesFor($productKind))],
            'product_kind' => ['required', Rule::enum(EventProductKind::class)],
            'description' => ['nullable', 'string', 'max:20000'],
            'event_date' => ['required', 'date', 'after_or_equal:today'],
            'event_time' => ['required', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'venue' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:4096'],
            'slug' => ['nullable', 'string', new EventSlugAvailable],
            'is_public' => ['boolean'],
            // event_date comparisons live in withValidator() below — a bare
            // 'before_or_equal:event_date' compares against event_date parsed
            // as midnight, which would reject any same-day deadline that has
            // a time on it at all (i.e. almost every real deadline).
            'rsvp_deadline' => ['nullable', 'date'],
            'guest_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'allow_plus_one' => ['boolean'],
            'show_guest_list' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->guardRsvpDeadline($validator);
            $this->guardEventTimeNotAlreadyPassedToday($validator);
        });
    }

    private function combinedEventInstant(): ?Carbon
    {
        $date = $this->input('event_date');
        $time = $this->input('event_time');

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
        $deadlineRaw = $this->input('rsvp_deadline');

        if (! is_string($deadlineRaw) || $deadlineRaw === '') {
            return;
        }

        $eventInstant = $this->combinedEventInstant();

        if ($eventInstant === null) {
            // event_date/event_time's own rules already report that problem.
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
     * after_or_equal:today only checks the date. A date of "today" paired
     * with a time earlier than right now still passes that rule, so an
     * event that has, in practice, already started could otherwise be
     * created. Scoped to today only — a genuinely past date is already
     * reported on event_date and does not need a second, redundant error.
     */
    private function guardEventTimeNotAlreadyPassedToday(Validator $validator): void
    {
        $date = $this->input('event_date');
        $eventInstant = $this->combinedEventInstant();

        if ($eventInstant === null || ! is_string($date)) {
            return;
        }

        try {
            $isToday = Carbon::parse($date)->isToday();
        } catch (\Throwable) {
            return;
        }

        if ($isToday && $eventInstant->lessThan(now()->subMinutes(5))) {
            $validator->errors()->add('event_time', 'That start time has already passed today. Please choose a later time.');
        }
    }
}
