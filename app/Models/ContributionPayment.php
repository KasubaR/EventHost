<?php

namespace App\Models;

use Database\Factories\ContributionPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One installment attempt against an EventContribution pledge. Structurally
 * a copy of TicketPayment (see plans/contributions.md) so it drops straight
 * into the existing LencoService / webhook plumbing unchanged.
 */
class ContributionPayment extends Model
{
    /** @use HasFactory<ContributionPaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_contribution_id',
        'provider',
        'payment_method',
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
        'cancelled_at',
        'webhook_received',
        'webhook_payload',
        'webhook_received_at',
        'metadata',
    ];

    /**
     * @return BelongsTo<EventContribution, $this>
     */
    public function contribution(): BelongsTo
    {
        return $this->belongsTo(EventContribution::class, 'event_contribution_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled', 'refunded'], true);
    }

    /**
     * A later provider `completed` may overwrite these. Refunded stays frozen.
     */
    public function canRecoverToCompleted(): bool
    {
        return in_array($this->status, ['failed', 'cancelled'], true);
    }

    /**
     * @param  Builder<ContributionPayment>  $query
     * @return Builder<ContributionPayment>
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'processing']);
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

    /**
     * Unlike TicketOrder (one order → one TicketPayment, so the order
     * reference doubles as the payment reference), a single EventContribution
     * can have several installment attempts, so each ContributionPayment
     * needs its own reference.
     */
    public static function generateReference(int $eventContributionId): string
    {
        return 'CTBP-'.$eventContributionId.'-'.now()->timestamp.'-'.bin2hex(random_bytes(4));
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

    public static function providerSettlementMatchesRecordedPayment(
        ?float $chargedAmountMajor,
        ?string $chargedCurrency,
        ContributionPayment $payment
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_contribution_id' => 'integer',
            'amount' => 'decimal:2',
            'lenco_response' => 'array',
            'bank_details' => 'array',
            'expires_at' => 'datetime',
            'failed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'webhook_received' => 'boolean',
            'webhook_payload' => 'array',
            'webhook_received_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
