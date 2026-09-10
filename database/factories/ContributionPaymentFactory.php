<?php

namespace Database\Factories;

use App\Models\ContributionPayment;
use App\Models\EventContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributionPayment>
 */
class ContributionPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_contribution_id' => EventContribution::factory(),
            'provider' => 'mtn',
            'payment_method' => 'mobile_money',
            'amount' => '50.00',
            'currency' => 'ZMW',
            'status' => 'pending',
            'payment_reference' => 'CTBP-'.fake()->unique()->numerify('##########'),
        ];
    }
}
