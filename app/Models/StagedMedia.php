<?php

namespace App\Models;

use App\Http\Controllers\EventInvitationMediaController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file already written to disk, waiting for the save that will reference it.
 *
 * @see EventInvitationMediaController
 */
class StagedMedia extends Model
{
    use HasFactory;

    protected $table = 'staged_media';

    public const SLOT_GALLERY = 'gallery';

    public const SLOT_HERO_PORTRAIT = 'hero_portrait';

    public const SLOT_COUPLE = 'couple';

    public const SLOT_COVER = 'cover';

    public const SLOT_AUDIO = 'audio';

    /** Beauty for Ashes addresses its four portrait slots positionally: speaker:0 … speaker:3. */
    public const SLOT_SPEAKER_PREFIX = 'speaker:';

    /**
     * Slots that hold exactly one file. Staging a second one replaces the first,
     * so the tile the user sees and the row in the table stay in agreement.
     */
    public const SINGLE_VALUE_SLOTS = [
        self::SLOT_HERO_PORTRAIT,
        self::SLOT_COVER,
        self::SLOT_AUDIO,
    ];

    protected $fillable = [
        'event_id',
        'user_id',
        'slot',
        'path',
        'original_name',
        'bytes',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public static function slots(): array
    {
        $slots = [
            self::SLOT_GALLERY,
            self::SLOT_HERO_PORTRAIT,
            self::SLOT_COUPLE,
            self::SLOT_COVER,
            self::SLOT_AUDIO,
        ];

        for ($i = 0; $i < 4; $i++) {
            $slots[] = self::SLOT_SPEAKER_PREFIX.$i;
        }

        return $slots;
    }

    public static function isSpeakerSlot(string $slot): bool
    {
        return (bool) preg_match('/^speaker:[0-3]$/', $slot);
    }

    public static function speakerSlot(int $index): string
    {
        return self::SLOT_SPEAKER_PREFIX.$index;
    }

    public static function isSingleValueSlot(string $slot): bool
    {
        return in_array($slot, self::SINGLE_VALUE_SLOTS, true) || self::isSpeakerSlot($slot);
    }

    /**
     * Every read of a staged row goes through here. Both columns matter: event_id
     * alone would let a collaborator on the same event consume rows staged in
     * someone else's open form.
     */
    public function scopeOwnedBy(Builder $query, int $eventId, int $userId): Builder
    {
        return $query->where('event_id', $eventId)->where('user_id', $userId);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
