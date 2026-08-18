<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'latitude' => $this->latitude === '' || $this->latitude === null ? null : $this->latitude,
            'longitude' => $this->longitude === '' || $this->longitude === null ? null : $this->longitude,
            'guest_limit' => $this->guest_limit === '' || $this->guest_limit === null ? null : $this->guest_limit,
            'description' => $this->description === '' ? null : $this->description,
            'venue' => $this->venue === '' ? null : $this->venue,
            'location_name' => $this->location_name === '' ? null : $this->location_name,
            'rsvp_deadline' => $this->rsvp_deadline === '' ? null : $this->rsvp_deadline,
        ]);
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
            'description' => ['nullable', 'string'],
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
            'is_public' => ['boolean'],
            'rsvp_deadline' => ['nullable', 'date', 'before_or_equal:event_date'],
            'guest_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'allow_plus_one' => ['boolean'],
            'show_guest_list' => ['boolean'],
            'photo_wall_enabled' => ['boolean'],
            'photo_wall_requires_approval' => ['boolean'],
        ];
    }
}
