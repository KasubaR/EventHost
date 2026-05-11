<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => null,
            'type' => 'spam',
            'message' => fake()->sentence(),
            'status' => Report::STATUS_PENDING,
        ];
    }

    public function withEvent(?Event $event = null): static
    {
        return $this->state(fn (array $attributes) => [
            'event_id' => $event?->getKey() ?? Event::factory(),
        ]);
    }
}
