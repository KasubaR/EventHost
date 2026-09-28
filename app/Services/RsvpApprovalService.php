<?php

namespace App\Services;

use App\Enums\RsvpApprovalStatus;
use App\Exceptions\RsvpApprovalException;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * plans/rsvp-host-approval.md — the host's own approve/reject actions on a Pending RSVP.
 * Mirrors TicketingActivationService::approve()/reject() (lock, guard, forceFill, notify
 * outside the transaction), with one difference: host_reviewed_by points at the event's
 * own host (a User), not an Admin — this is a host reviewing their own guest.
 */
class RsvpApprovalService
{
    public function approve(Rsvp $rsvp, User $host): void
    {
        $locked = DB::transaction(function () use ($rsvp, $host): Rsvp {
            /** @var Rsvp $locked */
            $locked = Rsvp::query()->whereKey($rsvp->id)->lockForUpdate()->firstOrFail();

            if ($locked->host_approval_status !== RsvpApprovalStatus::Pending) {
                throw new RsvpApprovalException('This RSVP is not awaiting approval.');
            }

            $locked->forceFill([
                'host_approval_status' => RsvpApprovalStatus::Approved,
                'host_reviewed_at' => now(),
                'host_reviewed_by' => $host->id,
                'host_rejection_note' => null,
            ])->save();

            return $locked;
        });

        // Outside the transaction: the confirmation/pass send doesn't need to hold the row
        // lock, and only needs to fire once the approval has actually committed.
        $locked->loadMissing(['guest', 'event']);
        app(CommunicationService::class)->dispatchApprovedRsvpNotifications($locked->event, $locked->guest, $locked);
    }

    public function reject(Rsvp $rsvp, User $host, string $note): void
    {
        $locked = DB::transaction(function () use ($rsvp, $host, $note): Rsvp {
            /** @var Rsvp $locked */
            $locked = Rsvp::query()->whereKey($rsvp->id)->lockForUpdate()->firstOrFail();

            if ($locked->host_approval_status !== RsvpApprovalStatus::Pending) {
                throw new RsvpApprovalException('This RSVP is not awaiting approval.');
            }

            $locked->forceFill([
                'host_approval_status' => RsvpApprovalStatus::Rejected,
                'host_reviewed_at' => now(),
                'host_reviewed_by' => $host->id,
                'host_rejection_note' => $note,
            ])->save();

            return $locked;
        });

        $locked->loadMissing(['guest', 'event']);
        app(CommunicationService::class)->sendRsvpRejection($locked->event, $locked->guest, $locked, $note);
    }
}
