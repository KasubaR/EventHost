<?php

namespace App\Models;

use Database\Factories\ContributionPayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Admin-recorded disbursement, written only by
 * App\Services\ContributionPayoutService. Rows are never edited or deleted —
 * same rule as TicketPayout / TicketRevenueEntry. See plans/contributions.md.
 */
class ContributionPayout extends Model
{
    /** @use HasFactory<ContributionPayoutFactory> */
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
            'paid_by' => 'integer',
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }
}
