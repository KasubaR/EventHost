<?php

namespace App\Support;

use App\Enums\SubscriptionTier;
use App\Models\User;
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

    /**
     * A one-time, non-tier purchase — e.g. `remove_branding`. Distinct from
     * `get()`/`plans`: an addon has no `credits`/`tier`/`guest_limit_default`
     * and never raises the buyer's subscription_tier.
     *
     * @return array<string, mixed>|null
     */
    public static function getAddon(string $key): ?array
    {
        $addon = config("billing.addons.{$key}");

        return is_array($addon) ? $addon : null;
    }

    /**
     * In-portal checkout link for an upgrade prompt, with the plan the user
     * needs preselected when a plan matches the tier.
     */
    public static function checkoutUrlForTier(SubscriptionTier $tier): string
    {
        return route('billing.show', self::exists($tier->value) ? ['plan' => $tier->value] : []);
    }

    /**
     * Not meaningful for `remove_branding` — it's an addon, not a tier, and
     * never reaches this call in practice (PaymentCompletionService::complete()
     * branches on that plan_key before ever calling tierForPlan()). Kept
     * throwing here rather than returning something misleading like
     * SubscriptionTier::None.
     */
    public static function tierForPlan(string $key): SubscriptionTier
    {
        if ($key === 'enterprise') {
            return SubscriptionTier::Enterprise;
        }

        $plan = self::get($key);

        if ($plan === null || ! isset($plan['tier'])) {
            throw new InvalidArgumentException("Unknown billing plan: {$key}");
        }

        return SubscriptionTier::from($plan['tier']);
    }

    public static function labelForPlanKey(string $key): string
    {
        if ($key === 'enterprise') {
            return 'Enterprise';
        }

        if ($key === 'public_registration_quote') {
            return 'Public Registration Fee';
        }

        $addon = self::getAddon($key);
        if ($addon !== null) {
            return (string) ($addon['label'] ?? $key);
        }

        $plan = self::get($key);

        return is_array($plan) ? (string) ($plan['label'] ?? $key) : $key;
    }

    public static function currency(): string
    {
        return (string) config('billing.currency', 'ZMW');
    }

    /**
     * List price for a subscription tier's matching config plan. Tiers with
     * no self-checkout plan (none, enterprise) are treated as K0 so a top-up
     * from none charges the full target amount.
     */
    public static function listAmountForTier(SubscriptionTier $tier): float
    {
        $plan = self::get($tier->value);

        if ($plan === null || ! isset($plan['amount'])) {
            return 0.0;
        }

        return (float) $plan['amount'];
    }

    /**
     * True when the buyer has unused event credit(s) and is selecting a plan
     * whose tier ranks strictly above their current tier — the unused-credit
     * top-up path (pay the price delta, grant no extra credit).
     */
    public static function isTierUpgrade(User $user, string $planKey): bool
    {
        if (! self::exists($planKey) || (int) $user->event_credits < 1) {
            return false;
        }

        return self::tierForPlan($planKey)->rank() > $user->subscriptionTierRank();
    }

    /**
     * Amount and credits to charge for a catalog plan purchase. When
     * isTierUpgrade() applies, amount is the list-price delta and credits
     * are 0 (the unused credit is kept). Otherwise full list price + plan
     * credits. Not used for enterprise quotes or addons.
     *
     * @return array{amount: float, credits: int, is_upgrade: bool}
     */
    public static function checkoutAmountFor(User $user, string $planKey): array
    {
        $plan = self::get($planKey);

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown billing plan: {$planKey}");
        }

        $listAmount = (float) $plan['amount'];
        $listCredits = (int) ($plan['credits'] ?? 1);

        if (! self::isTierUpgrade($user, $planKey)) {
            return [
                'amount' => $listAmount,
                'credits' => $listCredits,
                'is_upgrade' => false,
            ];
        }

        $delta = $listAmount - self::listAmountForTier($user->subscriptionTier());

        return [
            'amount' => max(0.0, $delta),
            'credits' => 0,
            'is_upgrade' => true,
        ];
    }

    /**
     * Guest-list size ceiling for a subscription tier — Base 150, Pro 300,
     * Pro+/Enterprise unlimited (null). Same numbers Event::guestCapacity()
     * enforces against Guest rows.
     */
    public static function guestLimitDefaultForTier(SubscriptionTier $tier): ?int
    {
        if ($tier->rank() >= SubscriptionTier::ProPlus->rank()) {
            return null;
        }

        $planKey = $tier->rank() >= SubscriptionTier::Pro->rank() ? 'pro' : 'base';
        $limit = config("billing.plans.{$planKey}.guest_limit_default");

        return is_int($limit) ? $limit : null;
    }

    /**
     * Tier that raises (or removes) the guest-list ceiling — for upgrade
     * CTAs. Null once already unlimited.
     */
    public static function nextGuestCapacityTier(SubscriptionTier $tier): ?SubscriptionTier
    {
        if (self::guestLimitDefaultForTier($tier) === null) {
            return null;
        }

        return $tier->rank() >= SubscriptionTier::Pro->rank()
            ? SubscriptionTier::ProPlus
            : SubscriptionTier::Pro;
    }

    /**
     * Catalog plans annotated with the amount this user would pay right now
     * (full list price or unused-credit top-up). Used by web + API checkout.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function plansForCheckout(User $user): array
    {
        $plans = [];

        foreach (self::all() as $key => $plan) {
            $checkout = self::checkoutAmountFor($user, $key);
            $plans[$key] = array_merge($plan, [
                'list_amount' => (float) $plan['amount'],
                'amount' => $checkout['amount'],
                'credits' => $checkout['credits'],
                'is_upgrade' => $checkout['is_upgrade'],
            ]);
        }

        return $plans;
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
