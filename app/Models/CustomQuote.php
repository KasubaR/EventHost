<?php

namespace App\Models;

use App\Enums\CustomQuoteStatus;
use App\Support\BillingPlan;
use Database\Factories\CustomQuoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomQuote extends Model
{
    /** @use HasFactory<CustomQuoteFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'amount',
        'credits_granted',
        'note',
        'status',
        'created_by',
        'payment_id',
        'notified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credits_granted' => 'integer',
            'status' => CustomQuoteStatus::class,
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @param  Builder<CustomQuote>  $query
     * @return Builder<CustomQuote>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CustomQuoteStatus::Pending);
    }

    public static function pendingFor(User|int $user): ?self
    {
        $userId = $user instanceof User ? $user->id : $user;

        return static::query()
            ->pending()
            ->where('user_id', $userId)
            ->latest('id')
            ->first();
    }

    public function isPending(): bool
    {
        return $this->status === CustomQuoteStatus::Pending;
    }

    public function formattedAmount(): string
    {
        $currency = BillingPlan::currency();
        $prefix = $currency === 'ZMW' ? 'K' : $currency.' ';

        return $prefix.number_format((float) $this->amount, 0);
    }
}
