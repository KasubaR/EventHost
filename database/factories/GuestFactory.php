<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'guest_group_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->e164PhoneNumber(),
            'invitation_token' => Str::random(48),
            'plus_one_allowed' => false,
            'invitation_sent' => false,
            'invitation_sent_at' => null,
            'rsvp_reminders_sent' => null,
        ];
    }

    public function withoutToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'invitation_token' => null,
        ]);
    }

    public function withPlusOne(): static
    {
        return $this->state(fn (array $attributes) => [
            'plus_one_allowed' => true,
        ]);
    }
}
