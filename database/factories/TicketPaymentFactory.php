<?php

namespace Database\Factories;

use App\Models\TicketOrder;
use App\Models\TicketPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketPayment>
 */
class TicketPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_order_id' => TicketOrder::factory(),
            'provider' => 'mtn',
            'payment_method' => 'mobile_money',
            'amount' => '200.00',
            'currency' => 'ZMW',
            'status' => 'pending',
            'payment_reference' => 'TKT-'.fake()->unique()->numerify('##########'),
        ];
    }
}
