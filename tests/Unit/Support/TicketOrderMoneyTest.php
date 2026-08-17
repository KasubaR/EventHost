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
