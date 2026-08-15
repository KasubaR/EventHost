<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\Guest;
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

        foreach (['phone', 'name'] as $field) {
            $v = $this->input($field);
            if (is_string($v)) {
                $t = trim($v);
                $this->merge([$field => $t === '' ? null : $t]);
            }
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('guests', 'email')
                    ->where(fn ($q) => $q->where('event_id', $event->id))
                    ->ignore($guest->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
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
