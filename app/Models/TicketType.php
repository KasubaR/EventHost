<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    /** @use HasFactory<TicketTypeFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'name',
        'description',
        'price',
        'quantity',
        'sales_starts_at',
        'sales_ends_at',
        'min_per_order',
        'max_per_order',
        'image_path',
        'terms',
        'refund_policy',
        'sort_order',
        'is_active',
    ];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
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

    /**
     * Issued seats that still count against capacity. Refunded/cancelled
     * tickets free their slot back up (refunds are a later phase, but the
     * capacity math is written to already do the right thing once they exist).
     */
    public function soldQuantity(): int
    {
        return $this->tickets()
            ->whereIn('status', [TicketStatus::Valid, TicketStatus::Used])
            ->count();
    }

    /**
     * Holds that still occupy capacity (unexpired, or tied to an in-flight
     * order). Optionally exclude one cart when a re-hold has already released
     * that cart's own rows in the same transaction.
     */
    public function heldQuantity(?string $excludingCartId = null): int
    {
        return $this->reservations()
            ->occupyingCapacity()
            ->when($excludingCartId !== null, fn ($q) => $q->where('cart_id', '!=', $excludingCartId))
            ->sum('quantity');
    }

    /**
     * Null means unlimited. Otherwise seats left to sell right now.
     */
    public function availableQuantity(?string $excludingCartId = null): ?int
    {
        if ($this->quantity === null) {
            return null;
        }

        return max(0, $this->quantity - $this->soldQuantity() - $this->heldQuantity($excludingCartId));
    }

    /**
     * Live holds or issued tickets that must survive a type delete.
     */
    public function hasBlockingSales(): bool
    {
        if ($this->tickets()->whereIn('status', [TicketStatus::Valid, TicketStatus::Used])->exists()) {
            return true;
        }

        return $this->reservations()->occupyingCapacity()->exists();
    }

    public function salesAreOpen(): bool
    {
        $now = now();

        if ($this->sales_starts_at !== null && $now->lt($this->sales_starts_at)) {
            return false;
        }

        if ($this->sales_ends_at !== null && $now->gt($this->sales_ends_at)) {
            return false;
        }

        return true;
    }

    public function isPurchasable(): bool
    {
        if (! $this->is_active || ! $this->salesAreOpen()) {
            return false;
        }

        $available = $this->availableQuantity();

        return $available === null || $available > 0;
    }

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->image_path;

        if ($path && ! str_contains($path, '://')) {
            return asset('storage/'.$path);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'price' => 'decimal:2',
            'quantity' => 'integer',
            'sales_starts_at' => 'datetime',
            'sales_ends_at' => 'datetime',
            'min_per_order' => 'integer',
            'max_per_order' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
