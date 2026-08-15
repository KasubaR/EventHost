<?php

namespace App\Models;

use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of event credits. Every change to `users.event_credits` writes a
 * row here — see App\Services\EventCreditService, the only place that should
 * touch the balance.
 */
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    public const REASON_PURCHASE = 'purchase';

    public const REASON_ADMIN_GRANT = 'admin_grant';

    public const REASON_EVENT_CREATED = 'event_created';

    public const REASON_EVENT_REDEFINED = 'event_redefined';

    public const REASON_REFUND = 'refund';

    /**
     * Labels for the admin credit history table.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        self::REASON_PURCHASE => 'Purchase',
        self::REASON_ADMIN_GRANT => 'Admin grant',
        self::REASON_EVENT_CREATED => 'Event created',
        self::REASON_EVENT_REDEFINED => 'Event redefined',
        self::REASON_REFUND => 'Refund',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'delta',
        'reason',
        'payment_id',
        'event_id',
        'balance_after',
        'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'balance_after' => 'integer',
        ];
    }
}
