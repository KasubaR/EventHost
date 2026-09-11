<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\PublicInvitationStatus;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /api/v1/events/{slug}/rsvp — form config for an open (no-token) RSVP, before any
 * guest exists. Twin of the web rsvp.open-show/rsvp.closed views. `status` is non-null
 * for every soft lifecycle state (cancelled/paused/gone/ended) exactly like
 * PublicEventResource's own status body for GET /api/v1/events/{slug} — `rsvp_open` is
 * always false whenever `status` is set, so the client never needs to special-case any
 * one status value to know whether the form can be submitted.
 */
class RsvpFormResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        private readonly Event $event,
        private readonly array $rsvpFormConfig,
        private readonly bool $rsvpOpen,
        private readonly ?PublicInvitationStatus $status,
    ) {
        parent::__construct($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event' => new PublicEventListResource($this->event),
            'rsvp_open' => $this->rsvpOpen,
            'rsvp_form' => $this->rsvpFormConfig,
            'status' => $this->status === null ? null : [
                'status' => $this->status->value,
                'title' => $this->status->title(),
                'message' => $this->status->message(),
            ],
        ];
    }
}
