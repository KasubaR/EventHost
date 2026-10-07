<?php

namespace App\Support;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Guest;

/**
 * What a guest's RSVP means at the door. A guest who declined, or whose request the host rejected, is not let in by a
 * scan (the host can still override it); a guest whose RSVP is not confirmed (Maybe, awaiting approval, no answer) is
 * let in with a warning for the staff. plans/rsvp-status-changes.md Phase 2.
 */
final class CheckInRsvpState
{
    /**
     * @return array{status: string, block: ?string, reason: ?string, warning: ?string}
     *                                                                                  `status` is accepted | maybe | declined | none (an additive scan-result field)
     */
    public static function for(Guest $guest): array
    {
        $rsvp = $guest->rsvp;
        $name = $guest->name;

        if ($rsvp === null) {
            return ['status' => 'none', 'block' => null, 'reason' => null, 'warning' => 'No RSVP on record for this guest.'];
        }

        if ($rsvp->status === RsvpStatus::Declined) {
            return [
                'status' => 'declined',
                'block' => $name.' declined this invitation, so they were not checked in.',
                'reason' => 'declined',
                'warning' => 'Guest declined this invitation.',
            ];
        }

        if ($rsvp->host_approval_status === RsvpApprovalStatus::Rejected) {
            return [
                'status' => $rsvp->status->value,
                'block' => 'The host did not approve '.$name.'\'s RSVP, so they were not checked in.',
                'reason' => 'rejected',
                'warning' => 'The host rejected this RSVP.',
            ];
        }

        if ($rsvp->status === RsvpStatus::Maybe) {
            return ['status' => 'maybe', 'block' => null, 'reason' => null, 'warning' => 'Answered "Maybe": RSVP not confirmed.'];
        }

        if ($rsvp->host_approval_status === RsvpApprovalStatus::Pending) {
            return ['status' => 'accepted', 'block' => null, 'reason' => null, 'warning' => 'RSVP is still waiting for the host\'s approval.'];
        }

        return ['status' => 'accepted', 'block' => null, 'reason' => null, 'warning' => null];
    }
}
