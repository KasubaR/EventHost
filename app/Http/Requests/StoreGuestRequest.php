<?php

namespace App\Http\Requests;

use App\Models\Event;
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

        foreach (['phone', 'name'] as $field) {
            $v = $this->input($field);
            if (is_string($v)) {
                $t = trim($v);
                $this->merge([$field => $t === '' ? null : $t]);
            }
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('guests', 'email')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'guest_group_id' => [
                'nullable',
                'integer',
                Rule::exists('guest_groups', 'id')->where(fn ($q) => $q->where('event_id', $event->id)),
            ],
            'plus_one_allowed' => ['sometimes', 'boolean'],
            'mark_invitation_sent' => ['sometimes', 'boolean'],
        ];
    }
}
