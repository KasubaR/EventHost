<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Support\GuestPassCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared shape for GET/POST /api/v1/rsvp/{token} and GET/POST
 * /api/v1/events/{slug}/rsvp — mirrors what the web RsvpController's
 * thanksByToken()/thanks() views render, returned synchronously in the same
 * response instead of via a redirect+session-flash round trip (there is no
 * session for a stateless API client to flash into).
 */
class RsvpResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        private readonly Event $event,
        private readonly Guest $guest,
        private readonly ?Rsvp $rsvp,
        private readonly int $maxAttendees,
        private readonly bool $showEntryPass,
    ) {
        parent::__construct($guest);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hasToken = $this->guest->invitation_token !== null;

        // Same branching as the web RsvpController::confirmationViewData() — a private
        // event has no public page at all, so a non-token (open) guest there gets no
        // view/change/share links; there genuinely isn't a URL that would work for them.
        $tokenOrPublicShowUrl = $hasToken
            ? route('rsvp.token.show', ['token' => $this->guest->invitation_token])
            : ($this->event->is_public ? route('events.public', ['slug' => $this->event->slug]) : null);

        return [
            'event' => new PublicEventListResource($this->event),
            'guest' => [
                'name' => $this->guest->name,
                'email' => $this->guest->email,
                'phone' => $this->guest->phone,
            ],
            'rsvp' => $this->rsvp === null ? null : [
                'status' => $this->rsvp->status->value,
                'attendee_count' => $this->rsvp->attendee_count,
                'message' => $this->rsvp->message,
                'meal_preference' => $this->rsvp->meal_preference,
                'transportation_note' => $this->rsvp->transportation_note,
                'song_request' => $this->rsvp->song_request,
            ],
            'max_attendees' => $this->maxAttendees,
            'entry_pass' => $this->entryPass(),
            'links' => [
                'view_invitation_url' => $tokenOrPublicShowUrl,
                'change_rsvp_url' => $hasToken
                    ? $tokenOrPublicShowUrl
                    : ($this->event->is_public ? route('rsvp.open.show', ['slug' => $this->event->slug]) : null),
                'share_url' => $hasToken
                    ? $this->guest->personalRsvpUrl()
                    : ($this->event->is_public ? route('events.public', ['slug' => $this->event->slug], absolute: true) : null),
            ],
        ];
    }

    /**
     * `available` and `check_in_qr_url` are the original contract and never change
     * meaning. Everything else is additive (plans/invitation-pass-card.md Phase 5):
     * URLs for the web pass page, the PDF and the card image, and the card's own
     * fields so a native client can render the pass itself instead of embedding a
     * web view. All of it is null when there is no pass, mirroring check_in_qr_url.
     *
     * @return array<string, mixed>
     */
    private function entryPass(): array
    {
        $pass = [
            'available' => $this->showEntryPass,
            'check_in_qr_url' => $this->showEntryPass ? $this->guest->checkInQrUrl() : null,
            'pass_url' => null,
            'pdf_url' => null,
            'image_url' => null,
            'card' => null,
        ];

        if (! $this->showEntryPass || $this->guest->invitation_token === null) {
            return $pass;
        }

        $token = $this->guest->invitation_token;
        $card = GuestPassCard::for($this->guest, $this->event, $this->rsvp);
        $startsAt = $this->event->startsAt();

        return array_merge($pass, [
            'pass_url' => route('rsvp.token.pass', ['token' => $token], absolute: true),
            'pdf_url' => route('rsvp.token.pass-download', ['token' => $token], absolute: true),
            'image_url' => route('rsvp.token.pass-image', ['token' => $token], absolute: true),
            'card' => [
                'event_name' => $card->eventName,
                'event_type_label' => $card->eventTypeLabel,
                // Bare calendar date when there is no start time — clients must not show
                // the 00:00 that startsAt() falls back to, hence the flag.
                'starts_at' => $startsAt?->toIso8601String(),
                'has_start_time' => $card->timeLine !== null,
                'venue' => $card->venue,
                'guest_name' => $card->guestName,
                'party_size' => $card->admits,
                'party_label' => $card->partyLabel(),
                'table' => $card->table,
                'state' => $card->state,
                'state_label' => $card->stateLabel(),
                'theme' => $card->theme,
            ],
        ]);
    }
}
