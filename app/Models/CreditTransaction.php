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

    public const REASON_EVENT_PUBLISHED = 'event_published';

    public const REASON_EVENT_REDEFINED = 'event_redefined';

    public const REASON_REFUND = 'refund';

    /**
     * Ledger-only correction for a balance that predates this table — see
     * App\Console\Commands\ReconcileLegacyCreditLedgerCommand. Never changes
     * `users.event_credits`, unlike every other reason here.
     */
    public const REASON_LEDGER_BACKFILL = 'ledger_backfill';

    /**
     * Labels for the admin credit history table.
     *
     * @var array<string, string>
     */
    public const REASONS = [
        self::REASON_PURCHASE => 'Purchase',
        self::REASON_ADMIN_GRANT => 'Admin grant',
        self::REASON_EVENT_CREATED => 'Event created',
        self::REASON_EVENT_PUBLISHED => 'Event published',
        self::REASON_EVENT_REDEFINED => 'Event redefined',
        self::REASON_REFUND => 'Refund',
        self::REASON_LEDGER_BACKFILL => 'Ledger backfill',
    ];

    /**
     * Rows are written only by EventCreditService via forceFill(), with one
     * exception: ReconcileLegacyCreditLedgerCommand writes a REASON_LEDGER_BACKFILL
     * row directly, since that command's entire point is to add a ledger row
     * without moving the balance — which EventCreditService's methods cannot do,
     * by design (every one of them moves both together).
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

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
