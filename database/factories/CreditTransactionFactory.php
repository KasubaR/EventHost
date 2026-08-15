<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    protected $model = CreditTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'delta' => 1,
            'reason' => CreditTransaction::REASON_ADMIN_GRANT,
            'payment_id' => null,
            'event_id' => null,
            'balance_after' => 1,
            'note' => null,
        ];
    }
}
