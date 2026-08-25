<?php

namespace Database\Factories;

use App\Enums\CustomQuoteStatus;
use App\Models\Admin;
use App\Models\CustomQuote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomQuote>
 */
class CustomQuoteFactory extends Factory
{
    protected $model = CustomQuote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'amount' => fake()->randomElement([5000, 10000, 15000, 25000]),
            'credits_granted' => fake()->numberBetween(1, 5),
            'note' => fake()->optional()->sentence(),
            'status' => CustomQuoteStatus::Pending,
            'created_by' => Admin::factory(),
            'payment_id' => null,
            'notified_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => CustomQuoteStatus::Pending]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => CustomQuoteStatus::Paid]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => CustomQuoteStatus::Cancelled]);
    }
}
