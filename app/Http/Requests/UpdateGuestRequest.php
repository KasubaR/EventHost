<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Guest;
use App\Rules\GuestPhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuestRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Guest $guest */
        $guest = $this->route('guest');

        $guest->loadMissing('event');

        return $this->user() !== null && $this->user()->id === $guest->event->user_id;
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
            'regenerate_invitation_token' => $this->boolean('regenerate_invitation_token'),
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
        /** @var Guest $guest */
        $guest = $this->route('guest');

        return [
            'name' => ['required', 'string', 'max:'.Guest::NAME_MAX],
            'email' => [
                'nullable',
                'email:rfc',
                'max:'.Guest::EMAIL_MAX,
                Rule::unique('guests', 'email')
                    ->where(fn ($q) => $q->where('event_id', $event->id))
                    ->ignore($guest->id),
            ],
            'phone' => [
                'bail', 'nullable', 'string', 'max:50',
                // A number saved before the format rule existed stays saveable unchanged, so editing
                // only the name never fails over a phone the host did not touch.
                function (string $attribute, mixed $value, Closure $fail) use ($guest): void {
                    if ($value !== $guest->phone) {
                        (new GuestPhoneNumber)->validate($attribute, $value, $fail);
                    }
                },
                function (string $attribute, mixed $value, Closure $fail) use ($event, $guest): void {
                    if (Guest::phoneAlreadyUsed($event, (string) $value, ignoreGuestId: $guest->id)) {
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
            'regenerate_invitation_token' => ['sometimes', 'boolean'],
            'mark_invitation_sent' => ['sometimes', 'boolean'],
        ];
    }
}
