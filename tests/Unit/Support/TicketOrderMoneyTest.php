<?php

namespace Tests\Unit\Support;

use App\Enums\CommissionMode;
use App\Support\TicketOrderMoney;
use Tests\TestCase;

class TicketOrderMoneyTest extends TestCase
{
    public function test_absorb_deducts_commission_from_the_host_and_charges_the_buyer_face_value(): void
    {
        $money = TicketOrderMoney::calculate(100, '5.00', CommissionMode::Absorb);

        $this->assertSame('100.00', $money->faceValue);
        $this->assertSame('5.00', $money->commissionPercent);
        $this->assertSame(CommissionMode::Absorb, $money->commissionMode);
        $this->assertSame('5.00', $money->commissionAmount);
        $this->assertSame('0.00', $money->buyerFee);
        $this->assertSame('95.00', $money->hostAmount);
        $this->assertSame('100.00', $money->buyerTotal);
    }

    public function test_pass_through_adds_commission_to_the_buyer_and_pays_the_host_face_value(): void
    {
        $money = TicketOrderMoney::calculate(100, '5.00', CommissionMode::PassThrough);

        $this->assertSame('100.00', $money->faceValue);
        $this->assertSame('5.00', $money->commissionAmount);
        $this->assertSame('5.00', $money->buyerFee);
        $this->assertSame('100.00', $money->hostAmount);
        $this->assertSame('105.00', $money->buyerTotal);
    }

    /**
     * "Fee rounding" — every case above uses inputs that divide evenly.
     * These deliberately land on a half-cent (or worse, a float that can't
     * be represented exactly in binary) to prove round() resolves it the
     * way accounting expects and the parts still reconcile to the penny.
     */
    public function test_commission_on_an_uneven_percentage_rounds_to_the_nearest_cent(): void
    {
        // 10.00 * 3.33% = 0.333 exactly — must round down to 0.33, not 0.34.
        $money = TicketOrderMoney::calculate('10.00', '3.33', CommissionMode::Absorb);

        $this->assertSame('0.33', $money->commissionAmount);
        $this->assertSame('9.67', $money->hostAmount);
        $this->assertReconciles($money);
    }

    public function test_a_half_cent_commission_rounds_up_and_still_reconciles(): void
    {
        // 99.99 * 5% = 4.9995 — the classic "exactly half a cent" case.
        $money = TicketOrderMoney::calculate('99.99', '5.00', CommissionMode::Absorb);

        $this->assertSame('5.00', $money->commissionAmount);
        $this->assertSame('94.99', $money->hostAmount);
        $this->assertReconciles($money);
    }

    /**
     * 20.10 * 5% = 1.005 in decimal, but that value can't be represented
     * exactly in binary floating point (it's actually stored fractionally
     * below 1.005) — the textbook case where naive round() implementations
     * round the wrong way. PHP's round() corrects for this since 5.3; this
     * pins that behavior so a future refactor (e.g. to bcmath) can't
     * silently regress it without a test noticing.
     */
    public function test_the_classic_point_zero_zero_five_float_boundary_rounds_up(): void
    {
        $money = TicketOrderMoney::calculate('20.10', '5.00', CommissionMode::Absorb);

        $this->assertSame('1.01', $money->commissionAmount);
        $this->assertReconciles($money);
    }

    public function test_pass_through_reconciles_exactly_with_a_fractional_commission(): void
    {
        $money = TicketOrderMoney::calculate('1234.56', '5.25', CommissionMode::PassThrough);

        $this->assertSame('64.81', $money->commissionAmount);
        $this->assertSame('64.81', $money->buyerFee);
        $this->assertSame('1234.56', $money->hostAmount);
        $this->assertSame('1299.37', $money->buyerTotal);
        $this->assertReconciles($money);
    }

    /**
     * A face value small enough that the commission rounds all the way down
     * to zero — must not error, and the host still gets the full face value.
     */
    public function test_a_commission_that_rounds_down_to_zero_does_not_error(): void
    {
        $money = TicketOrderMoney::calculate('0.01', '5.00', CommissionMode::Absorb);

        $this->assertSame('0.00', $money->commissionAmount);
        $this->assertSame('0.01', $money->hostAmount);
        $this->assertReconciles($money);
    }

    /**
     * Absorb: host + commission must equal face to the penny. Pass-through:
     * face + commission must equal what the buyer paid, and the host must
     * receive the full face value untouched. If rounding ever drifted by a
     * cent, this is what would catch it — the same invariant Phase 24's
     * reconciliation "ledger amount drift" check polices downstream, at the
     * source calculation instead.
     */
    private function assertReconciles(TicketOrderMoney $money): void
    {
        if ($money->commissionMode === CommissionMode::Absorb) {
            $this->assertEqualsWithDelta(
                (float) $money->faceValue,
                (float) $money->hostAmount + (float) $money->commissionAmount,
                0.001,
                'host_amount + commission_amount must equal face_value',
            );
            $this->assertSame($money->faceValue, $money->buyerTotal);
        } else {
            $this->assertEqualsWithDelta(
                (float) $money->buyerTotal,
                (float) $money->faceValue + (float) $money->commissionAmount,
                0.001,
                'face_value + commission_amount must equal buyer_total',
            );
            $this->assertSame($money->faceValue, $money->hostAmount);
            $this->assertSame($money->commissionAmount, $money->buyerFee);
        }
    }

    public function test_to_order_attributes_uses_the_stored_column_names(): void
    {
        $attributes = TicketOrderMoney::calculate(200, 5, CommissionMode::Absorb)->toOrderAttributes();

        $this->assertSame('200.00', $attributes['face_value']);
        $this->assertSame('5.00', $attributes['commission_percent']);
        $this->assertSame('10.00', $attributes['commission_amount']);
        $this->assertSame('0.00', $attributes['buyer_fee']);
        $this->assertSame('190.00', $attributes['host_amount']);
        $this->assertSame('200.00', $attributes['buyer_total']);
        $this->assertSame(CommissionMode::Absorb, $attributes['commission_mode']);
    }
}
