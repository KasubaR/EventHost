<?php

namespace App\Models;

use App\Support\GuestPhone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * What is left of a guest after the host deletes them (plans/guest-deletion-gaps.md Phase 3). Written by
 * GuestDeleter in the same transaction as the delete; never edited. The personal link is kept as a SHA-256 hash, so
 * `forToken()` can tell a removed link from one that never existed without the table holding a usable credential.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $removed_by
 * @property string|null $token_hash
 * @property string $name
 * @property string|null $email
 * @property string|null $phone_key
 * @property string|null $last_status
 * @property int $seats_freed
 * @property bool $invitation_sent
 * @property Carbon $removed_at
 * @property Carbon|null $notified_at
 */
class GuestRemoval extends Model
{
    public $timestamps = false;

    /** How many removals the host's "Removed guests" list shows. */
    public const LIST_LIMIT = 30;

    protected $fillable = [
        'event_id', 'removed_by', 'token_hash', 'name', 'email', 'phone_key',
        'last_status', 'seats_freed', 'invitation_sent', 'removed_at', 'notified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invitation_sent' => 'boolean',
            'removed_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The tombstone for a personal link, or null when that link was never removed (or never existed). */
    public static function forToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /** Same as forToken(), limited to one event: what a door scanner needs (a QR from another event is not "removed"). */
    public static function forTokenInEvent(int $eventId, string $token): ?self
    {
        return self::query()->where('event_id', $eventId)->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Whether this email or phone belongs to someone the host removed from the event. Used only for a self sign-up (open
     * form, group link), never for the host's own add or an import. plans/guest-deletion-gaps.md Phase 6.
     */
    public static function matchesContact(int $eventId, ?string $email, ?string $phone): bool
    {
        $email = filled($email) ? mb_strtolower(trim($email)) : null;
        $key = GuestPhone::key($phone);

        if ($email === null && $key === null) {
            return false;
        }

        return self::query()
            ->where('event_id', $eventId)
            ->where(function ($q) use ($email, $key): void {
                if ($email !== null) {
                    $q->orWhere('email', $email);
                }
                if ($key !== null) {
                    $q->orWhere('phone_key', $key);
                }
            })
            ->exists();
    }

    /**
     * What a door scanner is told when a scanned QR matches no guest: a removed guest gets a plain explanation (and a
     * stable `code`), anything else the same "no match" as before. Both are 404, which the scanners already handle.
     */
    public static function scanResponse(int $eventId, string $token): JsonResponse
    {
        if (self::forTokenInEvent($eventId, $token) !== null) {
            return response()->json(['message' => 'This guest was removed from the event by the host.', 'code' => 'guest_removed'], 404);
        }

        return response()->json(['message' => 'No matching invitation for this event.'], 404);
    }

    /**
     * Snapshots a guest about to be deleted. Call before `$guest->delete()`, with the RSVP loaded.
     */
    public static function recordFor(Guest $guest, ?int $removedBy): self
    {
        $token = filled($guest->invitation_token) ? (string) $guest->invitation_token : null;

        return self::query()->updateOrCreate(
            ['token_hash' => $token !== null ? self::hashToken($token) : null, 'event_id' => $guest->event_id, 'name' => $guest->name],
            [
                'removed_by' => $removedBy,
                'email' => filled($guest->email) ? mb_strtolower(trim((string) $guest->email)) : null,
                'phone_key' => GuestPhone::key($guest->phone),
                'last_status' => $guest->rsvp?->status?->value,
                'seats_freed' => $guest->rsvp?->heldSeats() ?? 0,
                'invitation_sent' => (bool) $guest->invitation_sent,
                'removed_at' => now(),
            ],
        );
    }

    /** Whether the host has anything to tell them: an address, not told yet, and they had a link or an answer. */
    public function canBeTold(): bool
    {
        return filled($this->email)
            && $this->notified_at === null
            && ($this->invitation_sent || $this->last_status !== null);
    }

    /** One line for the host's "Removed guests" list. */
    public function summary(): string
    {
        $parts = [];
        if ($this->seats_freed > 0) {
            $parts[] = 'had '.$this->seats_freed.' '.($this->seats_freed === 1 ? 'seat' : 'seats').' confirmed';
        } elseif ($this->last_status !== null) {
            $parts[] = 'had answered '.$this->last_status;
        }
        if ($this->invitation_sent) {
            $parts[] = 'was sent an invitation';
        }

        return $parts === [] ? 'had not answered' : implode(', ', $parts);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
