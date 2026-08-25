<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\BillingPlan;
use Illuminate\Support\Facades\Cache;

/**
 * Picks which checkoutable billing plan (if any) earns the "Most Popular"
 * badge. Thin data → null (no badge). Hysteresis stops flip-flops when
 * counts are close; the resolved value is cached so the homepage does not
 * recompute on every hit.
 */
class PopularBillingPlanResolver
{
    public const RESULT_CACHE_KEY = 'billing.popular_plan';

    /** Last published winner used for hysteresis across result-cache misses. */
    public const HOLDER_CACHE_KEY = 'billing.popular_plan.holder';

    /** Stored in cache when no plan should show the badge. */
    public const NONE = '';

    public function resolve(): ?string
    {
        $ttlHours = max(1, (int) config('billing.popular.cache_ttl_hours', 24));

        $cached = Cache::remember(self::RESULT_CACHE_KEY, now()->addHours($ttlHours), function (): string {
            $previous = Cache::get(self::HOLDER_CACHE_KEY);
            $previousKey = is_string($previous)
                && $previous !== self::NONE
                && BillingPlan::exists($previous)
                ? $previous
                : null;

            $winner = $this->computeWinner($previousKey);

            Cache::forever(self::HOLDER_CACHE_KEY, $winner ?? self::NONE);

            return $winner ?? self::NONE;
        });

        if (! is_string($cached) || $cached === self::NONE || ! BillingPlan::exists($cached)) {
            return null;
        }

        return $cached;
    }

    /**
     * Drop the short-lived result so the next resolve() recomputes. Keeps the
     * hysteresis holder intact — tests use this to exercise margin logic.
     */
    public function forgetResultCache(): void
    {
        Cache::forget(self::RESULT_CACHE_KEY);
    }

    /**
     * @return array<string, int>
     */
    public function salesCounts(): array
    {
        $eligible = array_keys(BillingPlan::all());
        if ($eligible === []) {
            return [];
        }

        $windowDays = max(1, (int) config('billing.popular.window_days', 30));
        $since = now()->subDays($windowDays);

        $rows = Payment::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since)
            ->whereIn('plan_key', $eligible)
            ->selectRaw('plan_key, COUNT(*) as c')
            ->groupBy('plan_key')
            ->pluck('c', 'plan_key');

        $counts = [];
        foreach ($eligible as $key) {
            $counts[$key] = (int) ($rows[$key] ?? 0);
        }

        return $counts;
    }

    private function computeWinner(?string $previousKey): ?string
    {
        $counts = $this->salesCounts();
        $minSales = max(1, (int) config('billing.popular.min_sales', 3));
        $margin = max(0.0, (float) config('billing.popular.lead_margin', 0.20));

        $qualifying = array_filter(
            $counts,
            static fn (int $count): bool => $count >= $minSales
        );

        if ($qualifying === []) {
            return null;
        }

        $previousStillValid = $previousKey !== null
            && isset($counts[$previousKey])
            && $counts[$previousKey] >= $minSales;

        if (! $previousStillValid) {
            return $this->uniqueLeader($qualifying);
        }

        $currentCount = $counts[$previousKey];
        $threshold = $currentCount * (1 + $margin);

        $challengerKey = null;
        $challengerCount = 0;
        foreach ($qualifying as $key => $count) {
            if ($key === $previousKey) {
                continue;
            }
            if ($count > $challengerCount) {
                $challengerKey = $key;
                $challengerCount = $count;
            }
        }

        if ($challengerKey !== null && $challengerCount >= $threshold) {
            return $challengerKey;
        }

        return $previousKey;
    }

    /**
     * @param  array<string, int>  $qualifying
     */
    private function uniqueLeader(array $qualifying): ?string
    {
        if ($qualifying === []) {
            return null;
        }

        $topCount = max($qualifying);
        $leaders = array_keys(array_filter(
            $qualifying,
            static fn (int $count): bool => $count === $topCount
        ));

        return count($leaders) === 1 ? $leaders[0] : null;
    }
}
