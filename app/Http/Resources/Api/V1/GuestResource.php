<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * $event is passed explicitly (rather than read off $guest->event) so callers
     * building many of these in a loop can eager-load the event once (with its
     * owner, for ownerHasPremiumEventTools()) instead of a lazy per-guest query.
     */
    public function __construct(Guest $resource, private readonly Event $event)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rsvp = $this->rsvp;

        // Same gate GuestController::qr() uses on the web — no RSVP-accepted
        // requirement here, unlike the guest-facing RSVP entry pass (Slice B1),
        // which additionally requires an accepted response. See the Slice C3
        // plan, design decision #4.
        $showQr = $this->invitation_token !== null && $this->event->ownerHasPremiumEventTools();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'guest_group_id' => $this->guest_group_id,
            'group_name' => $this->group?->name,
            'event_table_id' => $this->event_table_id,
            'table_label' => $this->tableLabel(),
            'plus_one_allowed' => $this->plus_one_allowed,
            'invitation_sent' => $this->invitation_sent,
            'invitation_sent_at' => $this->invitation_sent_at?->toIso8601String(),
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'checked_in_by_label' => $this->checkedInByLabel(),
            'personal_rsvp_url' => $this->personalRsvpUrl(),
            'check_in_qr_url' => $showQr ? $this->checkInQrUrl() : null,
            'rsvp' => $rsvp === null ? null : [
                'status' => $rsvp->status->value,
                'attendee_count' => $rsvp->attendee_count,
                'message' => $rsvp->message,
                'meal_preference' => $rsvp->meal_preference,
                'transportation_note' => $rsvp->transportation_note,
                'song_request' => $rsvp->song_request,
            ],
        ];
    }
}
