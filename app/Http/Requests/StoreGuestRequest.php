<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Guest;
use App\Rules\GuestPhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Event $event */
        $event = $this->route('event');

        return $this->user() !== null && $this->user()->id === $event->user_id;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $t = strtolower(trim($email));
            $this->merge(['email' => $t === '' ? null : $t]);
        }

        $phone = $this->input('phone');
        if (is_string($phone)) {
            $t = trim($phone);
            $this->merge(['phone' => $t === '' ? null : $t]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => Guest::cleanName($this->input('name'))]);
        }

        $this->merge([
            'plus_one_allowed' => $this->boolean('plus_one_allowed'),
            'mark_invitation_sent' => $this->boolean('mark_invitation_sent'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');

        return [
            'name' => ['required', 'string', 'max:'.Guest::NAME_MAX],
            'email' => [
                'nullable',
                'email:rfc',
                'max:'.Guest::EMAIL_MAX,
                Rule::unique('guests', 'email')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'phone' => [
                'bail', 'nullable', 'string', 'max:50', new GuestPhoneNumber,
                function (string $attribute, mixed $value, Closure $fail) use ($event): void {
                    if (Guest::phoneAlreadyUsed($event, (string) $value)) {
                        $fail('This phone number is already used by another guest for this event.');
                    }
                },
            ],
            'guest_group_id' => [
                'nullable',
                'integer',
                Rule::exists('guest_groups', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'event_table_id' => [
                'nullable',
                'integer',
                Rule::exists('event_tables', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'plus_one_allowed' => ['sometimes', 'boolean'],
            'mark_invitation_sent' => ['sometimes', 'boolean'],
        ];
    }
}
