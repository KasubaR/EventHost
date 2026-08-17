<?php

namespace App\Support;

use App\Enums\CommissionMode;

/**
 * The full money snapshot for one ticket order, computed once at checkout.
 * Stored on ticket_orders (and copied onto ticket_revenue_entries at fulfillment)
 * so reporting and settlements never re-run today's commission %.
 *
 * Mapping to stored columns:
 *   face_value           = ticket price (Σ unit_price × qty)
 *   commission_percent   = commission rate at checkout
 *   commission_amount    = rate applied to face_value
 *   buyer_fee            = extra the buyer pays (0 absorb, = commission pass-through)
 *   host_amount          = organizer earnings
 *   buyer_total          = total the buyer paid / Lenco charged
 *   commission_mode      = absorb | pass_through
 */
final readonly class TicketOrderMoney
{
    public function __construct(
        public string $faceValue,
        public string $commissionPercent,
        public CommissionMode $commissionMode,
        public string $commissionAmount,
        public string $buyerFee,
        public string $hostAmount,
        public string $buyerTotal,
    ) {}

    public static function calculate(
        float|int|string $faceValue,
        float|int|string $commissionPercent,
        CommissionMode $mode,
    ): self {
        $face = round((float) $faceValue, 2);
        $percent = round((float) $commissionPercent, 2);
        $commission = round($face * $percent / 100, 2);
        $passThrough = $mode === CommissionMode::PassThrough;
        $buyerFee = $passThrough ? $commission : 0.0;
        $hostAmount = $passThrough ? $face : round($face - $commission, 2);
        $buyerTotal = $passThrough ? round($face + $commission, 2) : $face;

        return new self(
            self::money($face),
            self::money($percent),
            $mode,
            self::money($commission),
            self::money($buyerFee),
            self::money($hostAmount),
            self::money($buyerTotal),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toOrderAttributes(): array
    {
        return [
            'face_value' => $this->faceValue,
            'commission_percent' => $this->commissionPercent,
            'commission_mode' => $this->commissionMode,
            'commission_amount' => $this->commissionAmount,
            'buyer_fee' => $this->buyerFee,
            'host_amount' => $this->hostAmount,
            'buyer_total' => $this->buyerTotal,
        ];
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
