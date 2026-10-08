<?php

namespace App\Services;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The host sets a guest's answer on their behalf: "she phoned to say she can't come", a plus-one the host knows about,
 * a late no-show or a walk-in recorded after the event started. plans/rsvp-status-changes.md Phase 5.
 *
 * It goes through RsvpSubmissionService::submit(), so the event lock, the seat limit, the group seat pool and the
 * history row are the same as for a guest. What differs is what the host is allowed: no deadline, no "already
 * checked in" refusal, no "rejection is final", and, only with the explicit tick, going past the guest limit.
 * Setting Accepted counts as the host's approval. The guest is NOT told unless the host asks for it, and the host
 * is never alerted about their own action.
 */
class HostRsvpOverrideService
{
    public function __construct(
        private readonly RsvpSubmissionService $submissions,
        private readonly CommunicationService $communication,
    ) {}

    /**
     * @throws ValidationException when the seat limit or group pool refuses and the host did not tick "allow over the limit"
     */
    public function set(Event $event, Guest $guest, RsvpStatus $status, int $seats, User $host, bool $allowOverLimit = false, bool $notifyGuest = false): Rsvp
    {
        $rsvp = $this->submissions->submit(
            $event,
            $guest,
            ['status' => $status, 'attendee_count' => $status === RsvpStatus::Accepted ? $seats : 0, 'message' => null],
            enforceDeadline: false,
            channel: RsvpChange::CHANNEL_HOST,
            actorUserId: $host->id,
            hostOverride: true,
            allowOverLimit: $allowOverLimit,
        );

        if ($notifyGuest && $rsvp->submissionChanged) {
            try {
                // The usual confirmation (and pass, where the guest has one); never the host alert.
                $this->communication->dispatchApprovedRsvpNotifications($event, $guest, $rsvp);
            } catch (\Throwable $e) {
                // The answer is saved; a mail failure must not undo or hide it.
                report($e);
            }
        }

        return $rsvp;
    }
}
