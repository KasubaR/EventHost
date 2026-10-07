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
    public function submit(Event $event, Guest $guest, array $payload, bool $enforceDeadline = true, bool $allowReductions = false): Rsvp
    {
        return DB::transaction(function () use ($event, $guest, $payload, $enforceDeadline, $allowReductions): Rsvp {
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
            if ($existing !== null && $previousHeldCount > $newAcceptedCount) {
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

            // A change that takes no more seats than the guest already holds is always allowed,
            // even when the host has since lowered the limit under current attendance.
            if ($locked->guest_limit !== null && $status === RsvpStatus::Accepted
                && $newAcceptedCount > $previousHeldCount) {
                $heldByOthers = EventAttendance::heldSeats($locked->id, $guest->id);
                $seatsLeft = max(0, $locked->guest_limit - $heldByOthers);

                if ($newAcceptedCount > $seatsLeft) {
                    throw GuestLimitReachedException::forSeats($seatsLeft, $previousHeldCount, $newAcceptedCount);
                }
            }

            // A guest in a group with a seat pool (plans/group-rsvp-links.md) may not take more
            // seats than remain. Every submit locks the event row above, so two people racing
            // for the last seat are serialized here — exactly one gets it.
            $group = $guest->guest_group_id !== null ? GuestGroup::query()->find($guest->guest_group_id) : null;
            $hasSeatPool = $group !== null && $group->hasSeatPool();

            if ($hasSeatPool && $status === RsvpStatus::Accepted
                && $newAcceptedCount > $group->seatsRemaining($guest->id)) {
                throw ValidationException::withMessages([
                    'status' => ['This group has no seats left. Please call the host for more information.'],
                ]);
            }

            // Guests who signed up through the group link are always held for the host, whatever
            // the event-wide toggle says. A guest the host added by hand is not.
            $requiresApproval = $locked->require_rsvp_approval
                || ($hasSeatPool && $guest->group_link_joined_at !== null);

            // Approval only (re)opens on a transition into Accepted from something else — a
            // fresh accept, or an accept after a prior decline/maybe. Editing attendee_count/
            // message while already Accepted keeps whatever decision the host already made
            // (Pending/Approved/Rejected), so a minor edit can't reopen a settled review.
            $approvalStatus = RsvpApprovalStatus::NotRequired;
            $resetReview = false;

            if ($status === RsvpStatus::Accepted && $requiresApproval) {
                $wasAccepted = $existing !== null && $existing->status === RsvpStatus::Accepted;

                if ($wasAccepted) {
                    $approvalStatus = $existing->host_approval_status;
                } else {
                    $approvalStatus = RsvpApprovalStatus::Pending;
                    $resetReview = true;
                }
            }

            $rsvpData = [
                'event_id' => $locked->id,
                'status' => $status,
                'attendee_count' => $attendeeCount,
                'message' => $payload['message'] ?? null,
                'host_approval_status' => $approvalStatus,
            ];

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

            return $rsvp;
        });
    }

    private static function normalizeMessage(?string $message): string
    {
        return trim((string) $message);
    }
}
