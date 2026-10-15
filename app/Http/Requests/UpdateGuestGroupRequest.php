<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\GuestGroup;
use App\Rules\GuestGroupNameAvailable;
use Illuminate\Foundation\Http\FormRequest;

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
            $this->merge(['name' => GuestGroup::cleanName($name)]);
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
                'max:'.GuestGroup::NAME_MAX,
                new GuestGroupNameAvailable($event, $guestGroup->id),
            ],
        ];
    }
}
