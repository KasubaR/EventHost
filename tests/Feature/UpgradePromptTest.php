<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Support\BillingPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpgradePromptTest extends TestCase
{
    use RefreshDatabase;

    private function lockedTemplate(): InvitationTemplate
    {
        return InvitationTemplate::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (InvitationTemplate $tpl) => $tpl->requiredTier() === SubscriptionTier::Pro)
            ?? throw new \RuntimeException('No Pro-tier template seeded.');
    }

    public function test_checkout_url_preselects_the_required_plan(): void
    {
        $this->assertSame(
            route('billing.show', ['plan' => 'pro']),
            BillingPlan::checkoutUrlForTier(SubscriptionTier::Pro)
        );
    }

    public function test_checkout_url_omits_plan_when_no_plan_matches_the_tier(): void
    {
        $this->assertSame(
            route('billing.show'),
            BillingPlan::checkoutUrlForTier(SubscriptionTier::None)
        );
    }

    public function test_locked_template_in_wizard_sends_user_to_billing_not_the_marketing_page(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('events.choose-template', $event));

        $response->assertOk();
        $response->assertSee(route('billing.show', ['plan' => 'pro']), escape: false);
        $response->assertDontSee('View plans', escape: false);
        $response->assertDontSee('#pricing', escape: false);
    }

    public function test_locked_template_in_library_sends_user_to_billing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('templates.index'));

        $response->assertOk();
        $response->assertSee(route('billing.show', ['plan' => 'pro']), escape: false);
        $response->assertDontSee('View plans', escape: false);
    }

    public function test_locked_template_preview_sends_user_to_billing(): void
    {
        $user = User::factory()->create();
        $tpl = $this->lockedTemplate();

        $response = $this->actingAs($user)->get(route('templates.preview', $tpl));

        $response->assertOk();
        $response->assertSee(route('billing.show', ['plan' => 'pro']), escape: false);
        $response->assertSee('Upgrade to Pro', escape: false);
        $response->assertDontSee('View plans', escape: false);
    }
}
