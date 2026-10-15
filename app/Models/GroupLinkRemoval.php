<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A group's shared RSVP link that no longer exists: the group was deleted, or the host turned the link off (plans/guest-groups-gaps.md
 * Phase 2). Written by GuestGroupDeleter. Keeps a hash of the token only, so it can recognise an old address but never be one.
 *
 * @property int $id
 * @property int $event_id
 * @property string $token_hash
 * @property string $reason
 * @property Carbon $removed_at
 */
class GroupLinkRemoval extends Model
{
    public const REASON_GROUP_DELETED = 'group_deleted';

    public const REASON_LINK_OFF = 'link_off';

    public $timestamps = false;

    protected $fillable = ['event_id', 'token_hash', 'reason', 'removed_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The record for an old group link, or null when that address was never closed (or never existed). */
    public static function forToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /** Remembers a group's link before it goes. A group with no link has nothing to remember. */
    public static function recordFor(GuestGroup $group, string $reason): ?self
    {
        if (blank($group->rsvp_token)) {
            return null;
        }

        return self::query()->updateOrCreate(
            ['token_hash' => self::hashToken((string) $group->rsvp_token)],
            ['event_id' => $group->event_id, 'reason' => $reason, 'removed_at' => now()],
        );
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
