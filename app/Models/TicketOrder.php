<?php

namespace App\Models;

use App\Enums\CommissionMode;
use App\Enums\TicketOrderStatus;
use Database\Factories\TicketOrderFactory;
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
            'buyer_total' => 'decimal:2',
            'host_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
