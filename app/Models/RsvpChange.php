<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a guest's answer (status or seats). Append-only; written by RsvpSubmissionService only.
 * plans/rsvp-status-changes.md Phase 4.
 */
class RsvpChange extends Model
{
    public const UPDATED_AT = null;

    public const CHANNEL_WEB_TOKEN = 'web_token';

    public const CHANNEL_WEB_OPEN = 'web_open';

    public const CHANNEL_GROUP = 'group';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_API = 'api';

    public const CHANNEL_HOST = 'host';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'rsvp_id', 'guest_id', 'event_id',
        'from_status', 'from_seats', 'to_status', 'to_seats',
        'channel', 'actor_user_id', 'over_limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => RsvpStatus::class,
            'to_status' => RsvpStatus::class,
            'from_seats' => 'integer',
            'to_seats' => 'integer',
            'over_limit' => 'boolean',
        ];
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * "Attending (2 seats)", "Not attending", "Maybe attending". Seats only mean something for an acceptance.
     */
    public static function answerLabel(RsvpStatus|string|null $status, ?int $seats): string
    {
        if ($status === null) {
            return 'No answer';
        }

        $status = $status instanceof RsvpStatus ? $status : RsvpStatus::from($status);

        return $status->attendanceLabel()
            .($status === RsvpStatus::Accepted && $seats !== null ? ' ('.$seats.' '.($seats === 1 ? 'seat' : 'seats').')' : '');
    }

    public function describe(): string
    {
        return self::answerLabel($this->from_status, $this->from_seats).' to '.self::answerLabel($this->to_status, $this->to_seats)
            .($this->over_limit ? ', over the guest limit' : '');
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            self::CHANNEL_WEB_TOKEN => 'Guest, personal link',
            self::CHANNEL_WEB_OPEN => 'Guest, open RSVP form',
            self::CHANNEL_GROUP => 'Guest, group link',
            self::CHANNEL_WHATSAPP => 'Guest, WhatsApp reply',
            self::CHANNEL_API => 'Guest, mobile app',
            self::CHANNEL_HOST => 'Host'.($this->actor?->name ? ' ('.$this->actor->name.')' : ''),
            default => $this->channel,
        };
    }
}
