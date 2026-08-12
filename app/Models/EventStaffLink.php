<?php

namespace App\Models;

use Database\Factories\EventStaffLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventStaffLink extends Model
{
    /** @use HasFactory<EventStaffLinkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'token',
        'label',
    ];

    protected static function booted(): void
    {
        static::creating(function (EventStaffLink $link): void {
            if (! is_string($link->token) || $link->token === '') {
                $link->token = self::generateUniqueToken();
            }
        });
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function scannerUrl(): string
    {
        return route('checkin.public.scan', ['staffToken' => $this->token], absolute: true);
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('token', $token)->exists());

        return $token;
    }
}
