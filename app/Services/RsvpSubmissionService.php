<?php

namespace App\Services;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RsvpSubmissionService
{
    /**
     * @param  array{status:RsvpStatus,attendee_count:int,message?:string|null}  $payload
     */
    public function submit(Event $event, Guest $guest, array $payload): Rsvp
    {
        return DB::transaction(function () use ($event, $guest, $payload): Rsvp {
            /** @var Event $locked */
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $status = $payload['status'];
            $maxAttendees = $locked->maxAttendeeSlotsForGuest($guest);

            $attendeeCount = $status->countsTowardGuestLimit()
                ? min(max(1, $payload['attendee_count']), $maxAttendees)
                : 0;

            $existing = Rsvp::query()
                ->where('guest_id', $guest->id)
                ->first();

            $previousAcceptedCount = ($existing && $existing->status === RsvpStatus::Accepted)
                ? $existing->attendee_count
                : 0;

            $newAcceptedCount = $status === RsvpStatus::Accepted ? $attendeeCount : 0;

            if ($locked->guest_limit !== null && $status === RsvpStatus::Accepted) {
                $totalAccepted = (int) Rsvp::query()
                    ->where('event_id', $locked->id)
                    ->where('status', RsvpStatus::Accepted)
                    ->sum('attendee_count');

                $effective = $totalAccepted - $previousAcceptedCount + $newAcceptedCount;

                if ($effective > $locked->guest_limit) {
                    throw ValidationException::withMessages([
                        'status' => ['This event has reached its guest limit for confirmed attendees.'],
                    ]);
                }
            }

            // Approval only (re)opens on a transition into Accepted from something else — a
            // fresh accept, or an accept after a prior decline/maybe. Editing attendee_count/
            // message while already Accepted keeps whatever decision the host already made
            // (Pending/Approved/Rejected), so a minor edit can't reopen a settled review.
            $approvalStatus = RsvpApprovalStatus::NotRequired;
            $resetReview = false;

            if ($status === RsvpStatus::Accepted && $locked->require_rsvp_approval) {
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
}
