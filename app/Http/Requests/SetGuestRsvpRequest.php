<?php

namespace App\Http\Requests;

use App\Enums\RsvpStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A host setting a guest's answer for them (web and API). The controller authorizes the guest; the seat count is 1 or
 * 2 because an invitation RSVP is only ever the guest and one plus-one.
 */
class SetGuestRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(RsvpStatus::class)],
            'attendee_count' => ['required_if:status,'.RsvpStatus::Accepted->value, 'nullable', 'integer', 'min:1', 'max:2'],
            'allow_over_limit' => ['nullable', 'boolean'],
            'notify_guest' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Choose the response to record.',
            'attendee_count.required_if' => 'Say how many seats: just the guest, or the guest and one more.',
            'attendee_count.max' => 'An invitation RSVP is the guest and at most one more person.',
        ];
    }
}
