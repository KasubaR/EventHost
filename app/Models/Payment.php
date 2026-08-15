<?php

namespace App\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'plan_key',
        'credits_granted',
        'user_ref',
        'payment_method',
        'provider',
        'amount',
        'currency',
        'status',
        'lenco_transaction_id',
        'lenco_reference',
        'lenco_status',
        'lenco_response',
        'payment_reference',
        'payment_instructions',
        'bank_details',
        'payment_url',
        'expires_at',
        'failure_reason',
        'failed_at',
        'completed_at',
        'notified_at',
        'credits_fulfilled_at',
        'credits_reversed_at',
        'cancelled_at',
        'webhook_received',
        'webhook_payload',
        'webhook_received_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credits_granted' => 'integer',
            'lenco_response' => 'array',
            'bank_details' => 'array',
            'expires_at' => 'datetime',
            'failed_at' => 'datetime',
            'completed_at' => 'datetime',
            'notified_at' => 'datetime',
            'credits_fulfilled_at' => 'datetime',
            'credits_reversed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'webhook_received' => 'boolean',
            'webhook_payload' => 'array',
            'webhook_received_at' => 'datetime',
            'metadata' => 'array',
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
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeStuck(Builder $query, int $hours): Builder
    {
        return $query->inProgress()
            ->where('created_at', '<=', now()->subHours($hours));
    }

    public static function findForLencoWebhook(?string $reference, ?string $transactionId, ?string $lencoReference): ?self
    {
        if ($reference !== null && $reference !== '') {
            $payment = self::query()->where('payment_reference', $reference)->first();
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($transactionId !== null && $transactionId !== '') {
            $payment = self::query()->where('lenco_transaction_id', $transactionId)->first();
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($lencoReference !== null && $lencoReference !== '') {
            return self::query()->where('lenco_reference', $lencoReference)->first();
        }

        return null;
    }

    public function isStuck(int $hours): bool
    {
        return ! $this->isTerminal()
            && in_array($this->status, ['pending', 'processing'], true)
            && $this->created_at !== null
            && $this->created_at->lte(now()->subHours($hours));
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled', 'refunded'], true);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function updateLencoStatus(string $lencoStatus, array $response = []): void
    {
        $this->fill([
            'lenco_status' => $lencoStatus,
            'lenco_response' => $response !== [] ? $response : $this->lenco_response,
        ]);
    }

    public static function providerSettlementMatchesRecordedPayment(
        ?float $chargedAmountMajor,
        ?string $chargedCurrency,
        Payment $payment
    ): bool {
        if ($chargedAmountMajor === null || $chargedCurrency === null || trim($chargedCurrency) === '') {
            return false;
        }

        if (strtoupper(trim($chargedCurrency)) !== strtoupper($payment->currency)) {
            return false;
        }

        $expected = round((float) $payment->amount, 2);

        return abs(round((float) $chargedAmountMajor, 2) - $expected) < 0.021;
    }
}
