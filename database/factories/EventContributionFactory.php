<?php

namespace Database\Factories;

use App\Enums\ContributionStatus;
use App\Models\Event;
use App\Models\EventContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventContribution>
 */
class EventContributionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = Event::factory()->state([
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ]);

        return [
            'event_id' => $event,
            'reference' => 'CTB-'.fake()->unique()->numerify('##########'),
            'contributor_name' => fake()->name(),
            'contributor_phone' => '0977123456',
            'contributor_email' => fake()->safeEmail(),
            'target_amount' => '100.00',
            'amount_paid' => '0.00',
            'currency' => 'ZMW',
            'status' => ContributionStatus::Pending,
        ];
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'amount_paid' => '40.00',
            'status' => ContributionStatus::Partial,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_paid' => $attributes['target_amount'] ?? '100.00',
            'status' => ContributionStatus::Completed,
            'completed_at' => now(),
        ]);
    }
}
