<?php

namespace App\Http\Resources\Api\V1;

use App\Models\GuestGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GuestGroup
 */
class GuestGroupResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guests_count' => $this->whenCounted('guests'),
            // plans/group-rsvp-links.md — all null unless the group has a shared RSVP link.
            'seat_limit' => $this->hasSeatPool() ? $this->seat_limit : null,
            'seats_taken' => $this->hasSeatPool() ? $this->seatsTaken() : null,
            'seats_pending' => $this->hasSeatPool() ? $this->seatsPending() : null,
            'rsvp_link' => $this->hasSeatPool() ? $this->rsvpUrl() : null,
            'rsvp_link_closed' => $this->hasSeatPool() ? $this->rsvp_link_closed_at !== null : null,
        ];
    }
}
