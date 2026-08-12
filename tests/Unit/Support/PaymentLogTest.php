<?php

namespace Tests\Unit\Support;

use App\Rules\ZambiaMobileMoneyPhone;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PaymentLogTest extends TestCase
{
    public function test_zambia_phone_rule_accepts_matching_mtn_number(): void
    {
        $validator = Validator::make(
            ['phone' => '0961234567'],
            ['phone' => [new ZambiaMobileMoneyPhone('mtn')]],
        );

        $this->assertFalse($validator->fails());
    }

    public function test_zambia_phone_rule_rejects_mismatched_operator(): void
    {
        $validator = Validator::make(
            ['phone' => '0771234567'],
            ['phone' => [new ZambiaMobileMoneyPhone('mtn')]],
        );

        $this->assertTrue($validator->fails());
    }
}
