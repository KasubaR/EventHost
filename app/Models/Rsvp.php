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
