<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->withoutCredits(),
            'plan_key' => 'base',
            'credits_granted' => 1,
            'user_ref' => 'USR-1',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'amount' => 450.00,
            'currency' => 'ZMW',
            'status' => 'pending',
            'payment_reference' => 'EH-1-'.now()->timestamp.'-'.bin2hex(random_bytes(4)),
            'expires_at' => now()->addHours(24),
            'metadata' => [
                'phone' => '+260971234567',
                'provider' => 'mtn',
            ],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Payment $payment): void {
            if ($payment->user_id) {
                $payment->user_ref = 'USR-'.$payment->user_id;
            }
        });
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'lenco_transaction_id' => 'col_'.fake()->uuid(),
            'completed_at' => now(),
        ]);
    }

    public function withTransactionId(?string $id = null): static
    {
        return $this->state(fn (array $attributes) => [
            'lenco_transaction_id' => $id ?? 'col_test_'.fake()->unique()->numerify('####'),
        ]);
    }
}
