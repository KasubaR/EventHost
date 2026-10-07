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
            'host_approval_status' => RsvpApprovalStatus::class,
            'host_reviewed_at' => 'datetime',
        ];
    }
}
