<?php

namespace App\Http\Requests;

use App\Models\Guest;
use App\Rules\AttendeeCount;
use App\Rules\GuestPhoneNumber;
use App\Services\GroupRsvpResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * plans/group-rsvp-links.md — only ever a request for seats (there is no decline here), so
 * a bot cannot mint unlimited zero-seat guests.
 */
class StoreGroupRsvpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (is_string($phone)) {
            $this->merge(['phone' => trim($phone) === '' ? null : trim($phone)]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => Guest::cleanName($this->input('name'))]);
        }

        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => strtolower(trim($email)) ?: null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $resolved = app(GroupRsvpResolver::class)->resolve((string) $this->route('token'));
        $perPerson = $resolved['event']->allow_plus_one ? 2 : 1;
        // The pool is checked by the service, under the lock, which says how many seats are actually left.

        return [
            'name' => ['required', 'string', 'max:'.Guest::NAME_MAX],
            'email' => ['required', 'email:rfc', 'max:'.Guest::EMAIL_MAX],
            'phone' => ['bail', 'required', 'string', 'max:50', new GuestPhoneNumber],
            'attendee_count' => ['bail', 'required', new AttendeeCount($perPerson, fn (): bool => true)],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
