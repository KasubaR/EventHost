<?php

namespace App\Services;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\GuestLimitReachedException;
use App\Exceptions\RsvpCheckedInException;
use App\Exceptions\RsvpClosedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use App\Support\EventAttendance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RsvpSubmissionService
{
    /**
     * Host action: take a confirmed guest back to one seat. This is the only way a plus-one already
     * confirmed goes away after plus-ones are switched off — switching them off never does it.
     * Returns null when there is no plus-one to remove (nothing changes).
     */
    public function removePlusOne(Event $event, Guest $guest): ?Rsvp
    {
        return DB::transaction(function () use ($event, $guest): ?Rsvp {
            // Same lock every submit takes, so this cannot interleave with a guest's own change.
            Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $rsvp = Rsvp::query()->where('guest_id', $guest->id)->lockForUpdate()->first();

            if ($rsvp === null || $rsvp->heldSeats() < 2) {
                return null;
            }

            $rsvp->update(['attendee_count' => 1]);

            return $rsvp;
        });
    }

    /**
     * @param  array{status:RsvpStatus,attendee_count:int,message?:string|null}  $payload
     */
    /**
     * `$enforceDeadline` is the deadline check, made under the event lock so a submit that passed a
     * request-level check just before the cut-off still cannot slip through (G5). Every caller is a
     * guest-facing path today; a host-initiated one would pass false.
     *
     * `$allowReductions` is for callers that identify the guest by a secret they hold (the personal
     * RSVP link, a verified WhatsApp number): after the deadline they may still decline or take
     * fewer seats, never more. The open RSVP form identifies a guest by email alone, so it never
     * gets this: anyone could otherwise cancel someone else's RSVP by typing their address.
     *
     * @throws RsvpClosedException
     */
    public function submit(Event $event, Guest $guest, array $payload, bool $enforceDeadline = true, bool $allowReductions = false, string $channel = RsvpChange::CHANNEL_WEB_TOKEN, ?int $actorUserId = null, bool $hostOverride = false, bool $allowOverLimit = false): Rsvp
    {
        // Only the host can go past the guest limit, and only on purpose.
        $allowOverLimit = $hostOverride && $allowOverLimit;

        return DB::transaction(function () use ($event, $guest, $payload, $enforceDeadline, $allowReductions, $channel, $actorUserId, $hostOverride, $allowOverLimit): Rsvp {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $status = $payload['status'];

            $existing = Rsvp::query()
                ->where('guest_id', $guest->id)
                ->first();

            // Seats this guest currently holds against the limit — a rejected RSVP holds none.
            $previousHeldCount = $existing?->heldSeats() ?? 0;

            // A seat already confirmed stays valid even if plus-ones were switched off since.
            $maxAttendees = max($locked->maxAttendeeSlotsForGuest($guest), $previousHeldCount);

            // The host knows about the plus-one even when the guest's own flag is off (an invitation RSVP is 1 or 2).
            if ($hostOverride) {
                $maxAttendees = max($maxAttendees, 2);
            }

            // Never store a different number from the one asked for: the request layers already
            // reject out-of-range counts, so a mismatch here is a channel that skipped them.
            if ($status->countsTowardGuestLimit()
                && ($payload['attendee_count'] < 1 || $payload['attendee_count'] > $maxAttendees)) {
                throw ValidationException::withMessages([
                    'attendee_count' => ['Choose between 1 and '.$maxAttendees.' attendee(s) for your response.'],
                ]);
            }

            $attendeeCount = $status->countsTowardGuestLimit() ? $payload['attendee_count'] : 0;

            $newAcceptedCount = $status === RsvpStatus::Accepted ? $attendeeCount : 0;

            // A guest who is already inside cannot cancel or reduce from here: check-in opens up to a day before the
            // start while reductions stay open until it. The guest row is locked too, so a scan and a decline cannot
            // both win. (Taking seats, or answering the same again, is fine.) plans/rsvp-status-changes.md Phase 2.
            if (! $hostOverride && $existing !== null && $previousHeldCount > $newAcceptedCount) {
                $checkedInAt = Guest::query()->whereKey($guest->id)->lockForUpdate()->value('checked_in_at');

                if ($checkedInAt !== null) {
                    throw new RsvpCheckedInException;
                }
            }

            if ($enforceDeadline && ! $locked->acceptsRsvpSubmissions()) {
                $mayReduce = $allowReductions && $locked->canReduceRsvp($existing);

                $isReduction = $mayReduce && (
                    $status === RsvpStatus::Declined
                    || ($existing->status !== RsvpStatus::Declined && $newAcceptedCount <= $previousHeldCount)
                );

                if (! $isReduction) {
                    throw new RsvpClosedException($mayReduce);
                }
            }

            // The host's rejection is final. A guest the host rejected cannot come back by declining and accepting
            // again, or by editing the request: only the very same request is a no-op, and nothing is queued for
            // review. (Declining is still allowed; the rejection is kept through it, see below.)
            // plans/rsvp-status-changes.md Phase 3.
            if (! $hostOverride && $status === RsvpStatus::Accepted && $existing?->host_approval_status === RsvpApprovalStatus::Rejected) {
                $identical = $existing->status === RsvpStatus::Accepted
                    && (int) $existing->attendee_count === $attendeeCount
                    && self::normalizeMessage($existing->message) === self::normalizeMessage($payload['message'] ?? null);

                if (! $identical) {
                    throw ValidationException::withMessages([
                        'status' => ['The host has already declined your request to attend. If you think this is a mistake, please contact the host.'],
                    ]);
                }

                $existing->submissionChanged = false;

                return $existing;
            }

            // A change that takes no more seats than the guest already holds is always allowed,
            // even when the host has since lowered the limit under current attendance.
            // A host may go past the limit only with the explicit tick, and the history row says so.
            $overLimit = false;

            if ($locked->guest_limit !== null && $status === RsvpStatus::Accepted
                && $newAcceptedCount > $previousHeldCount) {
                $heldByOthers = EventAttendance::heldSeats($locked->id, $guest->id);
                $seatsLeft = max(0, $locked->guest_limit - $heldByOthers);

                if ($newAcceptedCount > $seatsLeft) {
                    if (! $allowOverLimit) {
                        throw GuestLimitReachedException::forSeats($seatsLeft, $previousHeldCount, $newAcceptedCount);
                    }

                    $overLimit = true;
                }
            }

            // A guest in a group with a seat pool (plans/group-rsvp-links.md) may not take more
            // seats than remain. Every submit locks the event row above, so two people racing
            // for the last seat are serialized here — exactly one gets it.
            $group = $guest->guest_group_id !== null ? GuestGroup::query()->find($guest->guest_group_id) : null;
            $hasSeatPool = $group !== null && $group->hasSeatPool();

            if ($hasSeatPool && $status === RsvpStatus::Accepted
                && $newAcceptedCount > $group->seatsRemaining($guest->id)) {
                if (! $allowOverLimit) {
                    throw ValidationException::withMessages([
                        'status' => ['This group has no seats left. Please call the host for more information.'],
                    ]);
                }

                $overLimit = true;
            }

            // Guests who signed up through the group link are always held for the host, whatever
            // the event-wide toggle says. A guest the host added by hand is not.
            $requiresApproval = $locked->require_rsvp_approval
                || ($hasSeatPool && $guest->group_link_joined_at !== null);

            // Approval follows SEATS, not answers (plans/rsvp-status-changes.md Phase 3). `approved_seats` is how many
            // the host has approved, and it survives a Declined/Maybe round trip:
            //  - coming back to Accepted within the approved seats needs no new review and no new alert;
            //  - asking for more than was approved (a plus-one added later) reopens review for the extra seat only,
            //    while the guest keeps their pass for the seats already approved;
            //  - a guest with nothing approved yet starts a review, or keeps the one already open.
            // A rejection is kept through a decline (and refused above if the guest accepts again).
            $approvedSeats = $existing?->approvedSeatsOnFile() ?? 0;
            $approvalStatus = $existing?->host_approval_status === RsvpApprovalStatus::Rejected
                ? RsvpApprovalStatus::Rejected
                : RsvpApprovalStatus::NotRequired;
            $resetReview = false;

            if ($status === RsvpStatus::Accepted && $requiresApproval) {
                $reviewIsOpen = $existing !== null
                    && $existing->status === RsvpStatus::Accepted
                    && $existing->host_approval_status === RsvpApprovalStatus::Pending;

                if ($attendeeCount <= $approvedSeats) {
                    $approvalStatus = RsvpApprovalStatus::Approved;
                } elseif ($reviewIsOpen) {
                    // Still waiting on the host: editing the message or the count does not start a second review.
                    $approvalStatus = RsvpApprovalStatus::Pending;
                } else {
                    $approvalStatus = RsvpApprovalStatus::Pending;
                    $resetReview = true;
                }
            } elseif ($status === RsvpStatus::Accepted) {
                $approvalStatus = RsvpApprovalStatus::NotRequired;
            }
            // A host setting someone to Accepted IS the approval: no review is queued, any earlier rejection is lifted, and
            // the approved seats are what the host set. (Declined / Maybe from the host keep the usual rules above.)
            if ($hostOverride && $status === RsvpStatus::Accepted) {
                $approvalStatus = $requiresApproval ? RsvpApprovalStatus::Approved : RsvpApprovalStatus::NotRequired;
                $approvedSeats = $requiresApproval ? $attendeeCount : $approvedSeats;
                $resetReview = false;
            }

            $rsvpData = [
                'event_id' => $locked->id,
                'status' => $status,
                'attendee_count' => $attendeeCount,
                // The host sets the answer, not the guest's words: their message is kept as it was.
                'message' => $hostOverride ? $existing?->message : ($payload['message'] ?? null),
                'host_approval_status' => $approvalStatus,
                // Kept through a decline so coming back within it needs no new review.
                'approved_seats' => $approvedSeats > 0 ? $approvedSeats : null,
            ];

            if ($hostOverride && $status === RsvpStatus::Accepted) {
                $rsvpData['host_rejection_note'] = null;

                if ($requiresApproval) {
                    $rsvpData['host_reviewed_at'] = now();
                    $rsvpData['host_reviewed_by'] = $actorUserId;
                }
            }

            if ($resetReview) {
                // A new review episode — clear any reviewed_at/by/note left over from a
                // previous decision so the guest list doesn't show a stale rejection note
                // next to a freshly-Pending row.
                $rsvpData['host_reviewed_at'] = null;
                $rsvpData['host_reviewed_by'] = null;
                $rsvpData['host_rejection_note'] = null;
            }

            // The same answer again (double tap, refresh-resubmit, retry after a lost response) writes
            // nothing and tells callers not to notify. Compared under the event lock, so two racing
            // identical submits cannot both count as a change.
            if ($existing !== null
                && $existing->status === $status
                && (int) $existing->attendee_count === $attendeeCount
                && self::normalizeMessage($existing->message) === self::normalizeMessage($rsvpData['message'])
                && $existing->host_approval_status === $approvalStatus) {
                $existing->submissionChanged = false;

                return $existing;
            }

            try {
                /** @var Rsvp $rsvp */
                $rsvp = Rsvp::query()->updateOrCreate(
                    ['guest_id' => $guest->id],
                    $rsvpData,
                );
            } catch (UniqueConstraintViolationException) {
                // Concurrent request won the INSERT race on the unique(guest_id) constraint.
                // The row now exists — update it directly.
                //
                // Deliberately narrower than catching QueryException: a broad catch here would
                // also swallow unrelated write failures (a too-long column value, a deadlock, a
                // dropped connection) and retry them with the same doomed data, turning a clear
                // error into a confusing double failure. Only the actual race gets this fallback.
                Rsvp::query()
                    ->where('guest_id', $guest->id)
                    ->update($rsvpData);

                /** @var Rsvp $rsvp */
                $rsvp = Rsvp::query()->where('guest_id', $guest->id)->firstOrFail();
            }

            // A guest who declines gives up their seat at a table (guest or host, any channel). Only the move INTO Declined
            // does it: a table the host assigns later, to someone already declined, is theirs to keep.
            if ($status === RsvpStatus::Declined && $existing?->status !== RsvpStatus::Declined && $guest->event_table_id !== null) {
                Guest::query()->whereKey($guest->id)->update(['event_table_id' => null]);
                $guest->event_table_id = null;
            }

            $this->recordAnswerChange($existing, $rsvp, $channel, $actorUserId, $overLimit);

            return $rsvp;
        });
    }

    /**
     * Writes the history row when the ANSWER (status or seats) changed. A message or approval-only edit is not an answer
     * change. Runs inside submit()'s transaction, so the row and the RSVP are saved together or not at all.
     */
    private function recordAnswerChange(?Rsvp $before, Rsvp $after, string $channel, ?int $actorUserId, bool $overLimit = false): void
    {
        if ($before !== null
            && $before->status === $after->status
            && (int) $before->attendee_count === (int) $after->attendee_count) {
            return;
        }

        RsvpChange::query()->create([
            'rsvp_id' => $after->id,
            'guest_id' => $after->guest_id,
            'event_id' => $after->event_id,
            'from_status' => $before?->status,
            'from_seats' => $before !== null ? (int) $before->attendee_count : null,
            'to_status' => $after->status,
            'to_seats' => (int) $after->attendee_count,
            'channel' => $channel,
            'actor_user_id' => $actorUserId,
            'over_limit' => $overLimit,
        ]);

        if ($before !== null) {
            $after->previousAnswer = ['status' => $before->status->value, 'seats' => (int) $before->attendee_count];
        }
    }

    private static function normalizeMessage(?string $message): string
    {
        return trim((string) $message);
    }
}
