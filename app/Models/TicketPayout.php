<?php

namespace App\Models;

use Database\Factories\TicketPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-recorded disbursement (Phase 23). Written only by
 * App\Services\TicketPayoutService, alongside the App\Models\TicketRevenueEntry
 * (type=payout) that is the actual accounting entry — this row is the
 * admin-facing "who/when/how much/why" record. Rows are never edited or
 * deleted, same rule as TicketRevenueEntry.
 */
class TicketPayout extends Model
{
    /** @use HasFactory<TicketPayoutFactory> */
    use HasFactory;

    /**
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
     * @return BelongsTo<TicketRevenueEntry, $this>
     */
    public function revenueEntry(): BelongsTo
    {
        return $this->belongsTo(TicketRevenueEntry::class, 'ticket_revenue_entry_id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'paid_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'ticket_revenue_entry_id' => 'integer',
            'paid_by' => 'integer',
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }
}
