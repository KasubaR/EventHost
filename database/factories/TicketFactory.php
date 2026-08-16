<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_order_id' => TicketOrder::factory(),
            'ticket_order_item_id' => TicketOrderItem::factory(),
            'event_id' => Event::factory()->ticketed(),
            'ticket_type_id' => TicketType::factory(),
            'public_token' => Str::random(48),
            'attendee_name' => fake()->name(),
            'attendee_email' => fake()->safeEmail(),
            'attendee_phone' => null,
            'price_paid' => '200.00',
            'status' => TicketStatus::Valid,
            'issued_at' => now(),
        ];
    }
}
