<?php

namespace App\Http\Requests;

use App\Models\Event;
use App\Models\GuestGroup;
use App\Rules\GuestGroupNameAvailable;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGuestGroupRequest extends FormRequest
{
    /**
     * A group that is not in the event named in the URL does not exist as far as this request is concerned: 404 before anything about
     * who owns it can show (a 403 here would tell a caller that group id exists in someone else's event). plans/guest-groups-gaps.md Phase 5.
     */
    protected function groupMustBelongToEvent(): void
    {
        /** @var Event $event */
        $event = $this->route('event');
        /** @var GuestGroup $guestGroup */
        $guestGroup = $this->route('guest_group');

        abort_unless($guestGroup->event_id === $event->id, 404);
    }

    public function authorize(): bool
    {
        $this->groupMustBelongToEvent();

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
