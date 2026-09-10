<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\ContributionPayout;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionPayout>
 */
class ContributionPayoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'amount' => '50.00',
            'currency' => 'ZMW',
            'paid_on' => now()->toDateString(),
            'note' => null,
            'paid_by' => Admin::factory(),
        ];
    }
}
