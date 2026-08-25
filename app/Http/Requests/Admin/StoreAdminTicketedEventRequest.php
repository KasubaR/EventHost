<?php

namespace App\Http\Requests\Admin;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Rules\EventSlugAvailable;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminTicketedEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->check();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'product_kind' => EventProductKind::Ticketed->value,
            'latitude' => $this->latitude === '' || $this->latitude === null ? null : $this->latitude,
            'longitude' => $this->longitude === '' || $this->longitude === null ? null : $this->longitude,
            'description' => $this->description === '' ? null : $this->description,
            'venue' => $this->venue === '' ? null : $this->venue,
            'location_name' => $this->location_name === '' ? null : $this->location_name,
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
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('status', '!=', 'suspended')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'event_type' => ['required', Rule::in(Event::eventTypesFor(EventProductKind::Ticketed))],
            'product_kind' => ['required', Rule::in([EventProductKind::Ticketed->value])],
            'description' => ['nullable', 'string', 'max:20000'],
            'event_date' => ['required', 'date', 'after_or_equal:today'],
            'event_time' => ['required', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'venue' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'slug' => ['nullable', 'string', new EventSlugAvailable],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
