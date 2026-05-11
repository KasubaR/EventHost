<?php

namespace Database\Factories;

use App\Enums\SubscriptionTier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'phone' => null,
            'company_name' => null,
            'profile_photo' => null,
            'notification_preferences' => User::DEFAULT_NOTIFICATION_PREFERENCES,
            'status' => 'active',
            'last_login_at' => null,
            'last_login_ip' => null,
            'subscription_tier' => SubscriptionTier::Base,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'status' => 'pending',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_tier' => SubscriptionTier::Pro,
        ]);
    }

    public function proPlus(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_tier' => SubscriptionTier::ProPlus,
        ]);
    }
}
