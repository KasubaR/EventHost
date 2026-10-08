<?php

namespace App\Http\Requests;

use App\Rules\AttendeeCount;
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
        foreach (['name', 'phone'] as $field) {
            $v = $this->input($field);
            if (is_string($v)) {
                $this->merge([$field => trim($v) === '' ? null : trim($v)]);
            }
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'attendee_count' => ['bail', 'required', new AttendeeCount($perPerson, fn (): bool => true)],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
