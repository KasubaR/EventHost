<?php

namespace App\Models;

use App\Enums\CommissionMode;
use App\Enums\TicketOrderStatus;
use Database\Factories\TicketOrderFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TicketOrder extends Model
{
    /** @use HasFactory<TicketOrderFactory> */
    use HasFactory;

    /**
     * Money columns are a checkout snapshot (see TicketOrderMoney). Do not
     * recompute from TicketingSettings::commissionPercent() or the event's
     * current commission_mode — those can change after the buyer has paid.
     *
     * Semantic names used in reporting:
     *   ticket_price        → face_value
     *   commission_rate     → commission_percent
     *   commission_amount   → commission_amount
     *   buyer_fee           → buyer_fee (0 absorb; = commission pass-through)
     *   organizer_earnings  → host_amount
     *   total_paid          → buyer_total
     *
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'order_reference',
        'cart_id',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'status',
        'currency',
        'face_value',
        'commission_percent',
        'commission_mode',
        'commission_amount',
        'buyer_fee',
        'buyer_total',
        'host_amount',
        'paid_at',
        'expires_at',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<TicketOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TicketOrderItem::class);
    }

    /**
     * @return HasOne<TicketPayment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(TicketPayment::class);
    }

    /**
     * @return HasMany<TicketReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(TicketReservation::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function isPaid(): bool
    {
        return $this->status === TicketOrderStatus::Paid;
    }

    public static function generateReference(int $eventId): string
    {
        return 'TKT-'.$eventId.'-'.now()->timestamp.'-'.bin2hex(random_bytes(4));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function ticketPrice(): Attribute
    {
        return Attribute::get(fn () => $this->face_value);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function commissionRate(): Attribute
    {
        return Attribute::get(fn () => $this->commission_percent);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function organizerEarnings(): Attribute
    {
        return Attribute::get(fn () => $this->host_amount);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function totalPaid(): Attribute
    {
        return Attribute::get(fn () => $this->buyer_total);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'status' => TicketOrderStatus::class,
            'commission_mode' => CommissionMode::class,
            'face_value' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'buyer_fee' => 'decimal:2',
            'buyer_total' => 'decimal:2',
            'host_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
