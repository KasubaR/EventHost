<?php

namespace App\Models;

use App\Enums\EventStaffRole;
use Database\Factories\EventStaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventStaff extends Model
{
    /** @use HasFactory<EventStaffFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'user_id',
        'role',
        'email',
        'name',
        'invited_by',
        'invite_token',
        'invite_expires_at',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'user_id' => 'integer',
            'invited_by' => 'integer',
            'role' => EventStaffRole::class,
            'invite_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }

    /**
     * The real account's name once accepted (they may have typed something
     * different at signup than what the host originally entered); the name
     * the host entered on invite while still pending; the email as a last
     * resort for a legacy row from before `name` existed on this table.
     */
    public function displayName(): string
    {
        return $this->user?->name ?? $this->name ?? $this->email;
    }

    public function isExpired(): bool
    {
        return $this->invite_expires_at !== null && $this->invite_expires_at->isPast();
    }

    /**
     * Fresh 7-day token, replacing whatever was there before (used on both the
     * initial invite and "resend"). Not saved here — caller persists.
     */
    public function issueInviteToken(): void
    {
        $this->invite_token = self::generateUniqueToken();
        $this->invite_expires_at = now()->addDays(7);
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('invite_token', $token)->exists());

        return $token;
    }
}
