<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketPayout>
 */
class TicketPayoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->ticketed(),
            'ticket_revenue_entry_id' => null,
            'amount' => '100.00',
            'currency' => 'ZMW',
            'paid_on' => now()->toDateString(),
            'note' => null,
            'paid_by' => Admin::factory(),
        ];
    }
}
