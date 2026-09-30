<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\UpdateGuestGroupRequest;

/**
 * API twin of UpdateGuestGroupRequest — see StoreGuestApiRequest's docblock for the
 * authorize()-widening rationale (Slice C3 plan, design decision #1).
 */
class UpdateGuestGroupApiRequest extends UpdateGuestGroupRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Additive (plans/group-rsvp-links.md): `seat_limit` turns the group's shared RSVP link on or
     * changes its seat pool, an explicit null turns it off, `rsvp_link_closed` closes or reopens it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'seat_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'rsvp_link_closed' => ['sometimes', 'boolean'],
        ]);
    }
}
