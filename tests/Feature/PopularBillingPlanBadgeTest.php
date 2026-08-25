<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\PopularBillingPlanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PopularBillingPlanBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'billing.popular.window_days' => 30,
            'billing.popular.min_sales' => 3,
            'billing.popular.lead_margin' => 0.20,
            'billing.popular.cache_ttl_hours' => 24,
        ]);
    }

    public function test_homepage_hides_most_popular_badge_when_data_is_thin(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Most Popular', false)
            ->assertDontSee('price-card popular', false);
    }

    public function test_homepage_shows_badge_only_on_the_winning_plan(): void
    {
        Payment::factory()->completed()->count(10)->create(['plan_key' => 'pro']);
        Payment::factory()->completed()->count(2)->create(['plan_key' => 'base']);

        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Most Popular'));
        $this->assertMatchesRegularExpression(
            '/price-card[^>]*popular[^>]*>\s*<span class="popular-badge">Most Popular<\/span>\s*<div class="price-plan">Pro<\/div>/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/popular-badge">Most Popular<\/span>\s*<div class="price-plan">Base<\/div>/s',
            $html
        );
    }

    public function test_checkout_hides_most_popular_when_data_is_thin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('billing.show'))
            ->assertOk()
            ->assertDontSee('Most Popular', false)
            ->assertDontSee('billing-plan-popular-tag', false);
    }

    public function test_checkout_shows_badge_on_the_resolved_plan(): void
    {
        Payment::factory()->completed()->count(5)->create(['plan_key' => 'pro_plus']);
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('billing.show'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Most Popular'));
        $this->assertStringContainsString('billing-plan-popular-tag', $html);
        $this->assertTrue(app(PopularBillingPlanResolver::class)->resolve() === 'pro_plus');
    }
}
