<?php

namespace Tests\Unit\Services;

use App\Models\Payment;
use App\Services\PopularBillingPlanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PopularBillingPlanResolverTest extends TestCase
{
    use RefreshDatabase;

    private PopularBillingPlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->resolver = app(PopularBillingPlanResolver::class);

        config([
            'billing.popular.window_days' => 30,
            'billing.popular.min_sales' => 3,
            'billing.popular.lead_margin' => 0.20,
            'billing.popular.cache_ttl_hours' => 24,
        ]);
    }

    public function test_thin_data_returns_null(): void
    {
        $this->assertNull($this->resolver->resolve());

        Payment::factory()->completed()->count(2)->create(['plan_key' => 'pro']);
        $this->resolver->forgetResultCache();

        $this->assertNull($this->resolver->resolve());
    }

    public function test_clear_leader_among_qualifying_plans_wins(): void
    {
        Payment::factory()->completed()->count(10)->create(['plan_key' => 'pro']);
        Payment::factory()->completed()->count(2)->create(['plan_key' => 'base']);

        $this->assertSame('pro', $this->resolver->resolve());
    }

    public function test_tie_with_no_prior_winner_returns_null(): void
    {
        Payment::factory()->completed()->count(5)->create(['plan_key' => 'pro']);
        Payment::factory()->completed()->count(5)->create(['plan_key' => 'base']);

        $this->assertNull($this->resolver->resolve());
    }

    public function test_hysteresis_keeps_winner_when_margin_not_met(): void
    {
        Payment::factory()->completed()->count(10)->create(['plan_key' => 'pro']);
        $this->assertSame('pro', $this->resolver->resolve());

        Payment::factory()->completed()->count(11)->create(['plan_key' => 'base']);
        $this->resolver->forgetResultCache();

        // 11 base vs 10 pro — under the 20% lead (needs >= 12).
        $this->assertSame('pro', $this->resolver->resolve());
    }

    public function test_hysteresis_switches_when_challenger_beats_margin(): void
    {
        Payment::factory()->completed()->count(10)->create(['plan_key' => 'pro']);
        $this->assertSame('pro', $this->resolver->resolve());

        Payment::factory()->completed()->count(13)->create(['plan_key' => 'base']);
        $this->resolver->forgetResultCache();

        $this->assertSame('base', $this->resolver->resolve());
    }

    public function test_stale_winner_below_min_sales_is_cleared_then_new_leader_can_win(): void
    {
        Payment::factory()->completed()->count(3)->create([
            'plan_key' => 'pro',
            'completed_at' => now()->subDays(40),
        ]);
        // Force an initial holder of pro via the forever key (outside window so
        // counts are empty — thin → null — but we seed the holder to simulate
        // a previously popular plan that aged out of the window).
        Cache::forever(PopularBillingPlanResolver::HOLDER_CACHE_KEY, 'pro');
        Cache::forget(PopularBillingPlanResolver::RESULT_CACHE_KEY);

        Payment::factory()->completed()->count(4)->create(['plan_key' => 'base']);

        $this->assertSame('base', $this->resolver->resolve());
    }

    public function test_result_is_cached_across_calls(): void
    {
        Payment::factory()->completed()->count(5)->create(['plan_key' => 'pro']);
        $this->assertSame('pro', $this->resolver->resolve());

        Payment::factory()->completed()->count(20)->create(['plan_key' => 'base']);

        // Without forgetting the result cache, sales changes are invisible.
        $this->assertSame('pro', $this->resolver->resolve());
    }
}
