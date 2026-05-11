<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Rules\UserCanUseInvitationTemplate;
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
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preferred_invitation_template_id' => [
                'nullable',
                Rule::exists('invitation_templates', 'id')->where('is_active', true),
                new UserCanUseInvitationTemplate,
            ],
            'name' => ['required', 'string', 'max:255'],
            'event_type' => ['required', Rule::in(Event::EVENT_TYPES)],
            'description' => ['nullable', 'string'],
            'event_date' => ['required', 'date', 'after_or_equal:today'],
            'event_time' => ['required', 'regex:/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            'venue' => ['nullable', 'string', 'max:255'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:4096'],
            'is_public' => ['boolean'],
            'rsvp_deadline' => ['nullable', 'date', 'before_or_equal:event_date'],
            'guest_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'allow_plus_one' => ['boolean'],
            'show_guest_list' => ['boolean'],
        ];
    }
}
