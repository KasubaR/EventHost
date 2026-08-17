<?php

namespace App\Models;

use Database\Factories\TicketRevenueEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of an event's ticket revenue. Every change is written by
 * App\Services\TicketRevenueLedgerService, the only place that should ever
 * insert here — same shape and same rule as CreditTransaction/
 * EventCreditService for event credits. Rows are never edited or deleted;
 * a correction is a new row.
 */
class TicketRevenueEntry extends Model
{
    /** @use HasFactory<TicketRevenueEntryFactory> */
    use HasFactory;

    public const TYPE_SALE = 'sale';

    /**
     * Labels for the admin/host ledger view. Only `sale` has a writer —
     * payout/adjustment are later phases and are deliberately not listed
     * here yet (see plans/ticketing.md, "don't build ahead of the phase that
     * uses it"). Buyer refunds are handled off-platform, not as ledger rows.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_SALE => 'Sale',
    ];

    /**
     * Rows are written only by TicketRevenueLedgerService via forceFill().
     * Never edited or deleted by application code; deleting the related
     * event/order nulls the FKs rather than cascading the row away.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<TicketOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(TicketOrder::class, 'ticket_order_id');
    }

    /**
     * Optional link to a single ticket. Sale rows leave this null (one row
     * per order). Kept on the table so a later correction can key off a
     * ticket without another migration.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
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
            'event_id' => 'integer',
            'ticket_order_id' => 'integer',
            'ticket_id' => 'integer',
            'gross_amount' => 'decimal:2',
            'platform_fee' => 'decimal:2',
            'buyer_fee' => 'decimal:2',
            'host_amount' => 'decimal:2',
            'buyer_total' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }
}
