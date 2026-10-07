<?php

namespace App\Models;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use Database\Factories\RsvpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rsvp extends Model
{
    /** @use HasFactory<RsvpFactory> */
    use HasFactory;

    /**
     * Set by RsvpSubmissionService::submit(): false when the submit rewrote the same answer the guest
     * already had (double tap, refresh-resubmit, retry after a lost response). Declared, so Eloquent
     * keeps it out of the attributes and it is never persisted. Defaults to true so an RSVP made any
     * other way is still notified.
     */
    public bool $submissionChanged = true;

    /**
     * Set by RsvpSubmissionService::submit() when the ANSWER (status or seats) changed: what it was before, as plain
     * scalars (`status`, `seats`) so a queued notification can carry it. Null for a first answer or no answer change.
     *
     * @var array{status: string, seats: int}|null
     */
    public ?array $previousAnswer = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'guest_id',
        'status',
        'attendee_count',
        'message',
        'host_approval_status',
        'host_reviewed_at',
        'host_reviewed_by',
        'host_rejection_note',
        'approved_seats',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function hostReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_reviewed_by');
    }

    public function isAwaitingHostApproval(): bool
    {
        return $this->host_approval_status === RsvpApprovalStatus::Pending;
    }

    /**
     * Seats the host has approved for this guest. Rows approved before `approved_seats` existed (the migration backfills
     * them, but a hand-made row may not be) count as approved for the seats they hold.
     */
    public function approvedSeatsOnFile(): int
    {
        if ($this->approved_seats !== null) {
            return (int) $this->approved_seats;
        }

        return $this->host_approval_status === RsvpApprovalStatus::Approved ? (int) $this->attendee_count : 0;
    }

    /**
     * Seats the entry pass admits. While an extra seat waits for the host, the pass covers only what was approved.
     */
    public function passSeats(): int
    {
        if ($this->host_approval_status === RsvpApprovalStatus::Pending && $this->approvedSeatsOnFile() > 0) {
            return min((int) $this->attendee_count, $this->approvedSeatsOnFile());
        }

        return (int) $this->attendee_count;
    }

    /**
     * Seats this RSVP holds against the guest limit: an accepted response the host has not rejected.
     */
    public function heldSeats(): int
    {
        return ($this->status === RsvpStatus::Accepted
            && $this->host_approval_status !== RsvpApprovalStatus::Rejected)
            ? $this->attendee_count
            : 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RsvpStatus::class,
            'attendee_count' => 'integer',
            'approved_seats' => 'integer',
            'host_approval_status' => RsvpApprovalStatus::class,
            'host_reviewed_at' => 'datetime',
        ];
    }
}
