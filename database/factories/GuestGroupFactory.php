<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\GuestGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestGroup>
 */
class GuestGroupFactory extends Factory
{
    protected $model = GuestGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => fake()->randomElement(['Family', 'Friends', 'Work', 'VIP']),
        ];
    }
}
