<?php

namespace App\Http\Requests;

use App\Enums\RsvpStatus;
use App\Rules\AttendeeCount;
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
            'attendee_count' => [
                'bail',
                'required_if:status,'.RsvpStatus::Accepted->value,
                'nullable',
                new AttendeeCount(2, fn (): bool => $this->input('status') === RsvpStatus::Accepted->value),
            ],
            'allow_over_limit' => ['nullable', 'boolean'],
            'notify_guest' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Choose the response to record.',
            'attendee_count.required_if' => 'Say how many seats: just the guest, or the guest and one more.',
        ];
    }
}
