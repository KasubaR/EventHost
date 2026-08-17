<?php

namespace Database\Factories;

use App\Enums\CommissionMode;
use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\TicketOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketOrder>
 */
class TicketOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = Event::factory()->ticketed();

        return [
            'event_id' => $event,
            'order_reference' => 'TKT-'.fake()->unique()->numerify('##########'),
            'cart_id' => (string) Str::uuid(),
            'buyer_name' => fake()->name(),
            'buyer_email' => fake()->safeEmail(),
            'buyer_phone' => '0977123456',
            'status' => TicketOrderStatus::PendingPayment,
            'currency' => 'ZMW',
            'face_value' => '200.00',
            'commission_percent' => '5.00',
            'commission_mode' => CommissionMode::Absorb,
            'commission_amount' => '10.00',
            'buyer_fee' => '0.00',
            'buyer_total' => '200.00',
            'host_amount' => '190.00',
            'paid_at' => null,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => TicketOrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => TicketOrderStatus::PaymentProcessing,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => TicketOrderStatus::Failed,
        ]);
    }
}
