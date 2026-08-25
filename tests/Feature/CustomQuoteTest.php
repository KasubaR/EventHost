<?php

namespace Tests\Feature;

use App\Enums\CustomQuoteStatus;
use App\Enums\SubscriptionTier;
use App\Models\Admin;
use App\Models\CustomQuote;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CustomQuoteReadyNotification;
use App\Services\LencoService;
use App\Services\PaymentCompletionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class CustomQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function adminWithManageStatus(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function supportAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('support');

        return $admin;
    }

    public function test_admin_can_create_a_custom_quote_and_user_is_notified(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $admin = $this->adminWithManageStatus();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.custom-quote.store', $user), [
                'amount' => 12500,
                'credits_granted' => 3,
                'note' => 'Bespoke wedding site',
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $quote = CustomQuote::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($quote);
        $this->assertSame(CustomQuoteStatus::Pending, $quote->status);
        $this->assertEquals('12500.00', $quote->amount);
        $this->assertSame(3, $quote->credits_granted);
        $this->assertNotNull($quote->notified_at);

        Notification::assertSentTo($user, CustomQuoteReadyNotification::class);
    }

    public function test_support_cannot_create_a_custom_quote(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->supportAdmin(), 'admin')
            ->post(route('admin.users.custom-quote.store', $user), [
                'amount' => 5000,
                'credits_granted' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('custom_quotes', 0);
    }

    public function test_homepage_never_shows_a_personal_quote_amount(): void
    {
        $user = User::factory()->create();
        CustomQuote::factory()->for($user)->pending()->create([
            'amount' => 18750,
            'note' => 'Secret deal amount',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('18750', false)
            ->assertDontSee('K18,750', false)
            ->assertDontSee('Secret deal amount', false)
            ->assertSee('Contact Sales', false);
    }

    public function test_billing_shows_quote_amount_only_for_the_quoted_user(): void
    {
        $quoted = User::factory()->create();
        $other = User::factory()->create();
        CustomQuote::factory()->for($quoted)->pending()->create([
            'amount' => 9000,
            'note' => 'Agency package',
        ]);

        $this->actingAs($quoted)
            ->get(route('billing.show'))
            ->assertOk()
            ->assertSee('K9,000', false)
            ->assertSee('Agency package', false)
            ->assertSee('value="enterprise"', false)
            ->assertDontSee('Contact Sales', false);

        $this->actingAs($other)
            ->get(route('billing.show'))
            ->assertOk()
            ->assertDontSee('K9,000', false)
            ->assertSee('Contact Sales', false);
    }

    public function test_dashboard_shows_banner_for_pending_quote_and_hides_after_cancel(): void
    {
        $user = User::factory()->create();
        $quote = CustomQuote::factory()->for($user)->pending()->create(['amount' => 4200]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your custom Enterprise quote is ready', false)
            ->assertSee('K4,200', false)
            ->assertSee(route('billing.show', ['plan' => 'enterprise']), false);

        $admin = $this->adminWithManageStatus();
        $this->actingAs($admin, 'admin')
            ->delete(route('admin.users.custom-quote.destroy', [$user, $quote]))
            ->assertRedirect(route('admin.users.show', $user));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Your custom Enterprise quote is ready', false);
    }

    public function test_initiate_rejects_enterprise_without_a_valid_pending_quote(): void
    {
        $user = User::factory()->create(['phone' => '0961234567']);

        $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'enterprise',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ])->assertStatus(422);

        $foreign = CustomQuote::factory()->pending()->create(['amount' => 5000]);

        $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'enterprise',
            'quote_id' => $foreign->id,
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ])->assertStatus(422);
    }

    public function test_user_can_pay_enterprise_quote_via_lenco_and_completion_marks_paid(): void
    {
        $user = User::factory()->withoutCredits()->create([
            'phone' => '0961234567',
            'subscription_tier' => SubscriptionTier::None,
        ]);
        $quote = CustomQuote::factory()->for($user)->pending()->create([
            'amount' => 15000,
            'credits_granted' => 4,
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('generatePaymentReference')->once()->andReturn('EH-TEST-ENT');
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'transactionId' => 'col_enterprise_1',
            'status' => 'pending',
            'provider' => 'mtn',
            'lencoReference' => 'lr_ent_1',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'enterprise',
            'quote_id' => $quote->id,
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $payment = Payment::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('enterprise', $payment->plan_key);
        $this->assertEquals('15000.00', $payment->amount);
        $this->assertSame(4, $payment->credits_granted);
        $this->assertSame($quote->id, data_get($payment->metadata, 'quote_id'));

        $payment->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        app(PaymentCompletionService::class)->complete($payment->fresh());

        $user->refresh();
        $quote->refresh();

        $this->assertSame(4, $user->event_credits);
        $this->assertSame(SubscriptionTier::Enterprise, $user->subscriptionTier());
        $this->assertSame(CustomQuoteStatus::Paid, $quote->status);
        $this->assertSame($payment->id, $quote->payment_id);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Your custom Enterprise quote is ready', false);
    }

    public function test_billing_order_summary_reflects_the_quote_when_selected_via_query_string(): void
    {
        $user = User::factory()->create();
        CustomQuote::factory()->for($user)->pending()->create([
            'amount' => 9000,
        ]);

        $response = $this->actingAs($user)->get(route('billing.show', ['plan' => 'enterprise']));

        $response->assertOk();

        $content = $response->getContent();
        $summaryStart = strpos($content, 'id="billingSummaryPlan"');
        $this->assertNotFalse($summaryStart, 'Order summary plan element not found.');

        // The element right after the id attribute must show Enterprise / the
        // quote amount, not silently fall back to the first self-serve plan
        // (Base) — config('billing.plans') has no 'enterprise' key.
        $summarySnippet = substr($content, $summaryStart, 400);
        $this->assertStringContainsString('Enterprise', $summarySnippet);
        $this->assertStringContainsString('K9,000', $summarySnippet);
        $this->assertStringNotContainsString('>Base<', $summarySnippet);
    }

    public function test_cannot_create_two_pending_quotes_for_the_same_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $admin = $this->adminWithManageStatus();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.custom-quote.store', $user), [
                'amount' => 5000,
                'credits_granted' => 1,
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.custom-quote.store', $user), [
                'amount' => 6000,
                'credits_granted' => 2,
            ])
            ->assertSessionHasErrors('custom_quote');

        $this->assertSame(1, CustomQuote::query()->where('user_id', $user->id)->count());
    }

    public function test_reversing_a_completed_enterprise_payment_cancels_the_paid_quote(): void
    {
        $user = User::factory()->withoutCredits()->create([
            'subscription_tier' => SubscriptionTier::None,
        ]);
        $quote = CustomQuote::factory()->for($user)->pending()->create([
            'amount' => 15000,
            'credits_granted' => 4,
        ]);

        $payment = Payment::factory()->for($user)->create([
            'plan_key' => 'enterprise',
            'amount' => 15000,
            'credits_granted' => 4,
            'status' => 'completed',
            'metadata' => ['quote_id' => $quote->id],
        ]);

        app(PaymentCompletionService::class)->complete($payment->fresh());

        $quote->refresh();
        $this->assertSame(CustomQuoteStatus::Paid, $quote->status);

        app(PaymentCompletionService::class)->reverse($payment->fresh(), 'refunded');

        $quote->refresh();
        $this->assertSame(CustomQuoteStatus::Cancelled, $quote->status);
    }

    public function test_updating_a_pending_quote_re_notifies_the_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $quote = CustomQuote::factory()->for($user)->pending()->create(['amount' => 1000]);
        $admin = $this->adminWithManageStatus();

        $this->actingAs($admin, 'admin')
            ->patch(route('admin.users.custom-quote.update', [$user, $quote]), [
                'amount' => 2000,
                'credits_granted' => 2,
                'note' => 'Revised scope',
            ])
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertEquals('2000.00', $quote->fresh()->amount);
        Notification::assertSentTo($user, CustomQuoteReadyNotification::class);
    }
}
