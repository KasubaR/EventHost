<?php

namespace Database\Factories;

use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketOrderItem>
 */
class TicketOrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_order_id' => TicketOrder::factory(),
            'ticket_type_id' => TicketType::factory(),
            'ticket_type_name' => 'General Admission',
            'unit_price' => '200.00',
            'quantity' => 1,
            'subtotal' => '200.00',
        ];
    }
}
