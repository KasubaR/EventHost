<?php

namespace Database\Factories;

use App\Enums\TicketReservationStatus;
use App\Models\Event;
use App\Models\TicketReservation;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketReservation>
 */
class TicketReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->ticketed(),
            'ticket_type_id' => TicketType::factory(),
            'cart_id' => (string) Str::uuid(),
            'quantity' => 1,
            'unit_price_snapshot' => '100.00',
            'status' => TicketReservationStatus::Held,
            'expires_at' => now()->addMinutes(10),
            'ticket_order_id' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => TicketReservationStatus::Held,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
