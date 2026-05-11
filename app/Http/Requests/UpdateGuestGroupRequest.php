<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuestGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var GuestGroup $guestGroup */
        $guestGroup = $this->route('guest_group');
        $guestGroup->loadMissing('event');

        return $this->user() !== null && $this->user()->id === $guestGroup->event->user_id;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $t = trim($name);
            $this->merge(['name' => $t === '' ? null : $t]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Event $event */
        $event = $this->route('event');
        /** @var GuestGroup $guestGroup */
        $guestGroup = $this->route('guest_group');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('guest_groups', 'name')
                    ->where(fn ($q) => $q->where('event_id', $event->id))
                    ->ignore($guestGroup->id),
            ],
        ];
    }
}
