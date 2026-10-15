<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Twilio delivery statuses (from the status callback) that mean the message never reached the guest. */
    public const UNDELIVERED_STATUSES = ['failed', 'undelivered'];

    /** Twilio's delivery statuses in the order a message moves through them; a late callback never moves a row back. */
    public const DELIVERY_ORDER = ['queued' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5, 'undelivered' => 5];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'guest_id',
        'channel',
        'type',
        'status',
        'delivery_status',
        'error_code',
        'idempotency_key',
        'provider_message_id',
        'sent_at',
        'response',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function wasUndelivered(): bool
    {
        return in_array($this->delivery_status, self::UNDELIVERED_STATUSES, true);
    }

    /** The host-facing reason a WhatsApp message did not arrive, from Twilio's error code. */
    public function undeliveredReason(): string
    {
        return match ((string) $this->error_code) {
            '63003' => "This number isn't on WhatsApp",
            '63024' => 'Message blocked by recipient',
            default => "Couldn't deliver",
        };
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
