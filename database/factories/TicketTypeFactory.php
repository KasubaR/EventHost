<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory()->ticketed(),
            'name' => fake()->randomElement(['General Admission', 'VIP', 'Early Bird', 'Student']),
            'badge_color' => TicketType::DEFAULT_BADGE_COLOR,
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomElement(['50.00', '100.00', '200.00', '350.00']),
            'quantity' => fake()->optional(0.7)->numberBetween(20, 500),
            'sales_starts_at' => null,
            'sales_ends_at' => null,
            'min_per_order' => 1,
            'max_per_order' => 10,
            'image_path' => null,
            'terms' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
