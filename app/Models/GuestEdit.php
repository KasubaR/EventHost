<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved edit of a guest's own details. `changes` maps a field to [old, new]; `link_replaced`, `invitation_reset` and `over_pool` are
 * plain `true` flags (the personal link itself is never stored here). plans/guest-editing-gaps.md Phase 5.
 *
 * @property int $id
 * @property int $event_id
 * @property int $guest_id
 * @property int|null $user_id
 * @property array<string, mixed> $changes
 */
class GuestEdit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event_id', 'guest_id', 'user_id', 'changes'];

    /** Field labels for the history list. */
    private const LABELS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'group' => 'Group',
        'table' => 'Table',
        'plus_one' => 'Plus-one allowed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Records an edit when something changed. Returns null (and writes nothing) for a no-op.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function record(Guest $guest, ?int $userId, array $changes): ?self
    {
        if ($changes === []) {
            return null;
        }

        return static::query()->create([
            'event_id' => $guest->event_id,
            'guest_id' => $guest->id,
            'user_id' => $userId,
            'changes' => $changes,
        ]);
    }

    /**
     * One line per change, for the guest's edit page.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];
        // getAttribute, not $this->changes: inside a model that name is Eloquent's own record of what the last save changed.
        $changes = (array) $this->getAttribute('changes');

        foreach ($changes as $field => $change) {
            if (isset(self::LABELS[$field]) && is_array($change) && count($change) === 2) {
                [$old, $new] = $change;
                $lines[] = self::LABELS[$field].': '.self::show($field, $old).' → '.self::show($field, $new);
            }
        }

        if (($changes['link_replaced'] ?? false) === true) {
            $lines[] = 'Personal link replaced';
        }

        if (($changes['invitation_reset'] ?? false) === true) {
            $lines[] = 'Invitation marked not sent (new contact)';
        }

        if (($changes['over_pool'] ?? false) === true) {
            $lines[] = 'Moved past the group\'s seat pool';
        }

        return $lines;
    }

    private static function show(string $field, mixed $value): string
    {
        if ($field === 'plus_one') {
            return $value ? 'yes' : 'no';
        }

        return is_string($value) && $value !== '' ? $value : 'none';
    }
}
