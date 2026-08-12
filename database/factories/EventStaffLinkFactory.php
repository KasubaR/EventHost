<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventStaffLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventStaffLink>
 */
class EventStaffLinkFactory extends Factory
{
    protected $model = EventStaffLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'token' => EventStaffLink::generateUniqueToken(),
            'label' => fake()->optional()->randomElement(['Front door', 'Side entrance', "Sarah's phone"]),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
