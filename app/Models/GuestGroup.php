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

    /** The most groups one event can have (plans/guest-groups-gaps.md Phase 4). Existing events over it keep theirs; only new groups are refused. */
    public const MAX_PER_EVENT = 200;

    /** @var array{taken: int, pending: int}|null seat totals loaded for a whole page of groups at once; not an attribute, so it is never saved */
    private ?array $preloadedSeats = null;

    /** The longest group name: the column is VARCHAR(191) on MySQL (see Guest::NAME_MAX). */
    public const NAME_MAX = Guest::NAME_MAX;

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
    /** A typed group name as it is stored: the same cleaning guest names get (plans/guest-groups-gaps.md Phase 3). Null when nothing is left. */
    public static function cleanName(?string $name): ?string
    {
        return Guest::cleanName($name);
    }

    /** What "the same name" means: cleaned and lower-cased, so spacing, case and invisible characters never make a second group. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower(self::cleanName($name) ?? '');
    }

    /** @return array{members: int, joiners: int, pending: int} counts for the confirm text; uses preloaded withCount values when present */
    public function affectedCounts(): array
    {
        return [
            'members' => (int) ($this->guests_count ?? $this->guests()->count()),
            'joiners' => (int) ($this->joiners_count ?? $this->guests()->whereNotNull('group_link_joined_at')->count()),
            'pending' => (int) ($this->pending_count ?? $this->guests()
                ->whereHas('rsvp', fn ($q) => $q->where('host_approval_status', RsvpApprovalStatus::Pending))->count()),
        ];
    }

    /** What deleting this group does, for the confirm dialog (plans/guest-groups-gaps.md Phase 1). */
    public function removalWarning(): string
    {
        ['members' => $members, 'joiners' => $joiners, 'pending' => $pending] = $this->affectedCounts();

        $text = 'Remove '.$this->name.'?';
        $text .= $members === 0
            ? ' It has no guests.'
            : ' '.$members.' '.Str::plural('guest', $members).' stay on the list without a group.';
        $text .= $this->reviewSentence($joiners, $pending);
        if ($this->rsvp_token !== null) {
            $text .= ' Its shared RSVP link stops working.';
        }

        return $text;
    }

    /** What turning the shared link off does, for the confirm dialog. */
    public function linkOffWarning(): string
    {
        ['members' => $members, 'joiners' => $joiners, 'pending' => $pending] = $this->affectedCounts();

        return 'Turn off this link? The address stops working; '.$members.' '.Str::plural('member', $members).' and their RSVPs stay.'
            .$this->reviewSentence($joiners, $pending);
    }

    private function reviewSentence(int $joiners, int $pending): string
    {
        if ($joiners === 0) {
            return '';
        }

        $text = ' '.$joiners.' signed up through the link and stay under your review';

        return $text.($pending > 0 ? ', and '.$pending.' '.($pending === 1 ? 'is' : 'are').' waiting for you.' : '.');
    }

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
        if ($exceptGuestId === null && $this->preloadedSeats !== null) {
            return $this->preloadedSeats['taken'];
        }

        return (int) Rsvp::query()
            ->whereIn('guest_id', $this->guests()->select('guests.id'))
            ->when($exceptGuestId !== null, fn ($q) => $q->where('guest_id', '!=', $exceptGuestId))
            ->where('status', RsvpStatus::Accepted)
            ->where('host_approval_status', '!=', RsvpApprovalStatus::Rejected)
            ->sum('attendee_count');
    }

    public function seatsPending(): int
    {
        if ($this->preloadedSeats !== null) {
            return $this->preloadedSeats['pending'];
        }

        return (int) Rsvp::query()
            ->whereIn('guest_id', $this->guests()->select('guests.id'))
            ->where('status', RsvpStatus::Accepted)
            ->where('host_approval_status', RsvpApprovalStatus::Pending)
            ->sum('attendee_count');
    }

    /**
     * Seats taken and awaiting approval for every group in the list that has a seat pool, in one grouped query, so a page of groups
     * costs one query instead of two per group. The groups then answer seatsTaken() / seatsPending() from memory.
     *
     * @param  iterable<GuestGroup>  $groups
     */
    public static function preloadSeatTotals(iterable $groups): void
    {
        $pooled = collect($groups)->filter(fn (GuestGroup $group) => $group->hasSeatPool());
        if ($pooled->isEmpty()) {
            return;
        }

        $totals = Rsvp::query()
            ->join('guests', 'guests.id', '=', 'rsvps.guest_id')
            ->whereIn('guests.guest_group_id', $pooled->pluck('id')->all())
            ->where('rsvps.status', RsvpStatus::Accepted)
            ->where('rsvps.host_approval_status', '!=', RsvpApprovalStatus::Rejected)
            ->groupBy('guests.guest_group_id')
            ->selectRaw('guests.guest_group_id as group_id, SUM(rsvps.attendee_count) as taken, SUM(CASE WHEN rsvps.host_approval_status = ? THEN rsvps.attendee_count ELSE 0 END) as pending', [RsvpApprovalStatus::Pending->value])
            ->get()
            ->keyBy('group_id');

        foreach ($pooled as $group) {
            $group->preloadedSeats = [
                'taken' => (int) ($totals[$group->id]->taken ?? 0),
                'pending' => (int) ($totals[$group->id]->pending ?? 0),
            ];
        }
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
