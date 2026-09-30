<?php

namespace App\Models;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use Database\Factories\GuestGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GuestGroup extends Model
{
    /** @use HasFactory<GuestGroupFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'name',
        'seat_limit',
        'rsvp_token',
        'rsvp_link_closed_at',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<Guest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    /**
     * Has a shared RSVP link with a seat pool (plans/group-rsvp-links.md).
     */
    public function hasSeatPool(): bool
    {
        return $this->seat_limit !== null && $this->rsvp_token !== null;
    }

    public function isLinkOpen(): bool
    {
        return $this->hasSeatPool() && $this->rsvp_link_closed_at === null;
    }

    /**
     * Seats spoken for: accepted RSVPs from this group's guests that the host has not
     * rejected. A pending request holds its seats, so approving can never overshoot the pool.
     * Read live, never stored — the caller takes the event row lock before trusting it.
     */
    public function seatsTaken(?int $exceptGuestId = null): int
    {
        return (int) Rsvp::query()
            ->whereIn('guest_id', $this->guests()->select('guests.id'))
            ->when($exceptGuestId !== null, fn ($q) => $q->where('guest_id', '!=', $exceptGuestId))
            ->where('status', RsvpStatus::Accepted)
            ->where('host_approval_status', '!=', RsvpApprovalStatus::Rejected)
            ->sum('attendee_count');
    }

    public function seatsPending(): int
    {
        return (int) Rsvp::query()
            ->whereIn('guest_id', $this->guests()->select('guests.id'))
            ->where('status', RsvpStatus::Accepted)
            ->where('host_approval_status', RsvpApprovalStatus::Pending)
            ->sum('attendee_count');
    }

    public function seatsRemaining(?int $exceptGuestId = null): int
    {
        return max(0, (int) $this->seat_limit - $this->seatsTaken($exceptGuestId));
    }

    public function rsvpUrl(): ?string
    {
        return $this->rsvp_token !== null ? route('group-rsvp.show', ['token' => $this->rsvp_token]) : null;
    }

    /**
     * Turn the shared link on (or back on), minting a fresh secret the first time.
     */
    public function enableLink(int $seatLimit): void
    {
        $this->forceFill([
            'seat_limit' => $seatLimit,
            'rsvp_token' => $this->rsvp_token ?? Str::random(48),
            'rsvp_link_closed_at' => null,
        ])->save();
    }

    /**
     * Turn the link off. Members and their RSVPs are untouched.
     */
    public function disableLink(): void
    {
        $this->forceFill(['rsvp_token' => null, 'seat_limit' => null, 'rsvp_link_closed_at' => null])->save();
    }
}
