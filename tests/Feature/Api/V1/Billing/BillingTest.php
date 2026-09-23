<?php

namespace Tests\Feature\Api\V1\Billing;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice E — /api/v1/billing/*. show() is new (Api\V1\BillingController);
 * initiate()/verify()/verifyByReference() hit the EXISTING web
 * PaymentController directly (see routes/api.php's comment) — this proves
 * that controller is reachable and behaves the same under a Bearer token as
 * it does under the web session, without re-testing its internals (already
 * covered by tests/Feature/PaymentInitiationTest.php et al.).
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_show_returns_plans_and_currency(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson(route('api.v1.billing.show'));

        $response->assertOk();
        $response->assertJsonStructure(['plans', 'currency', 'banks', 'bank_transfer_enabled', 'popular_plan_key']);
    }

    public function test_show_annotates_upgrade_pricing_when_user_has_unused_credit(): void
    {
        $user = User::factory()->create([
            'subscription_tier' => SubscriptionTier::Base,
            'event_credits' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson(route('api.v1.billing.show'));

        $response->assertOk()
            ->assertJsonPath('plans.pro.is_upgrade', true)
            ->assertJsonPath('plans.pro.amount', 300)
            ->assertJsonPath('plans.pro.list_amount', 750)
            ->assertJsonPath('plans.pro.credits', 0)
            ->assertJsonPath('plans.base.is_upgrade', false);
    }

    public function test_show_requires_authentication(): void
    {
        $this->getJson(route('api.v1.billing.show'))->assertUnauthorized();
    }

    public function test_initiate_rejects_a_duplicate_in_progress_payment(): void
    {
        $user = User::factory()->withoutCredits()->create();
        Payment::factory()->for($user)->create(['status' => 'pending']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson(route('api.v1.billing.initiate'), [
                'plan_key' => 'base',
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ]);

        $response->assertStatus(409);
    }

    public function test_initiate_requires_authentication(): void
    {
        $this->postJson(route('api.v1.billing.initiate'), ['plan_key' => 'base'])
            ->assertUnauthorized();
    }
}
