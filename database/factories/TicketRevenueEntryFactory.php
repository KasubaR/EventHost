<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketRevenueEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketRevenueEntry>
 */
class TicketRevenueEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->ticketed(),
            'ticket_order_id' => TicketOrder::factory(),
            'type' => TicketRevenueEntry::TYPE_SALE,
            'gross_amount' => '200.00',
            'platform_fee' => '10.00',
            'buyer_fee' => '0.00',
            'host_amount' => '190.00',
            'buyer_total' => '200.00',
            'currency' => 'ZMW',
            'balance_after' => '190.00',
        ];
    }
}
