<?php

namespace App\Models;

use App\Enums\TicketReservationStatus;
use Database\Factories\TicketReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReservation extends Model
{
    /** @use HasFactory<TicketReservationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'ticket_type_id',
        'cart_id',
        'quantity',
        'unit_price_snapshot',
        'status',
        'expires_at',
        'ticket_order_id',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * @return BelongsTo<TicketOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(TicketOrder::class, 'ticket_order_id');
    }

    public function isActive(): bool
    {
        return $this->status === TicketReservationStatus::Held
            && $this->expires_at !== null
            && ($this->expires_at->isFuture() || $this->ticket_order_id !== null);
    }

    /**
     * Holds that still occupy capacity: unexpired, or already tied to an
     * in-flight / paid order so expiry cannot free the seat out from under
     * a pending Lenco collection.
     *
     * @param  Builder<TicketReservation>  $query
     * @return Builder<TicketReservation>
     */
    public function scopeOccupyingCapacity(Builder $query): Builder
    {
        return $query->where('status', TicketReservationStatus::Held)
            ->where(function (Builder $inner) {
                $inner->where('expires_at', '>', now())
                    ->orWhereNotNull('ticket_order_id');
            });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'ticket_type_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'status' => TicketReservationStatus::class,
            'expires_at' => 'datetime',
            'ticket_order_id' => 'integer',
        ];
    }
}
