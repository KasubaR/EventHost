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

    /**
     * One link, one URL shape per event — never both. A staff link is always
     * created from inside its own event's check-in page (guest or ticket),
     * which already knows which kind it is, so there's nothing to ask the
     * organizer here; this just routes to the scanner that understands this
     * event's credential type (see PublicCheckInController vs
     * PublicTicketCheckInController).
     */
    public function scannerUrl(): string
    {
        if ($this->event?->isTicketed()) {
            return route('tickets.checkin.public.scan', ['staffToken' => $this->token], absolute: true);
        }

        return route('checkin.public.scan', ['staffToken' => $this->token], absolute: true);
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * What a scan through this link is recorded as. Snapshotted onto the
     * ticket/guest at check-in time, so it has to identify the door even when
     * the organizer never labelled the link — two unlabelled links at two
     * gates would otherwise be indistinguishable in an audit.
     */
    public function scanLabel(): string
    {
        return $this->label ?: 'Staff link #'.$this->id;
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('token', $token)->exists());

        return $token;
    }
}
