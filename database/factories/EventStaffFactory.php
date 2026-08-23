<?php

namespace Database\Factories;

use App\Enums\EventStaffRole;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventStaff>
 */
class EventStaffFactory extends Factory
{
    protected $model = EventStaff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'user_id' => null,
            'role' => EventStaffRole::CheckIn,
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'invited_by' => User::factory(),
            'invite_token' => EventStaff::generateUniqueToken(),
            'invite_expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => EventStaffRole::Manager,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => User::factory(),
            'invite_token' => null,
            'invite_expires_at' => null,
            'accepted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'invite_expires_at' => now()->subDay(),
        ]);
    }
}
