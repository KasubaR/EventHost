<?php

namespace App\Support;

use App\Enums\SubscriptionTier;
use InvalidArgumentException;

class BillingPlan
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return config('billing.plans', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        $plan = config("billing.plans.{$key}");

        return is_array($plan) ? $plan : null;
    }

    public static function exists(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function tierForPlan(string $key): SubscriptionTier
    {
        $plan = self::get($key);

        if ($plan === null || ! isset($plan['tier'])) {
            throw new InvalidArgumentException("Unknown billing plan: {$key}");
        }

        return SubscriptionTier::from($plan['tier']);
    }

    public static function currency(): string
    {
        return (string) config('billing.currency', 'ZMW');
    }

    /**
     * @return array<int, array{name: string}>
     */
    public static function fallbackBanks(): array
    {
        $names = config('billing.fallback_banks', []);

        if (! is_array($names)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $name): array => ['name' => $name],
            array_filter($names, static fn ($name): bool => is_string($name) && $name !== '')
        ));
    }
}
