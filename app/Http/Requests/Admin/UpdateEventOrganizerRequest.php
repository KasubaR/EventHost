<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin correcting a ticketed event's organizer contact details at any point,
 * including after ticket sales are approved. Same limits as the host's own form
 * (App\Http\Requests\UpdateEventOrganizerRequest); the payout account is not here.
 */
class UpdateEventOrganizerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('admin')->user()?->can('ticketing.organizer_manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organizer_name' => ['required', 'string', 'max:120'],
            'organizer_phone' => ['required', 'string', 'max:40', 'regex:/^[0-9+\s().-]{7,}$/'],
            'organizer_email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organizer_phone.regex' => 'Enter a phone number guests can call, for example 0977 123 456.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organizer_name' => 'organizer name',
            'organizer_phone' => 'contact number',
            'organizer_email' => 'contact email',
        ];
    }
}
