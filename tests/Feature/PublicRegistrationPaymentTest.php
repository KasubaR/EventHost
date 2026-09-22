<?php

namespace Tests\Feature;

use App\Enums\PublicRegistrationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use App\Services\PaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Step 2 of plans/public-private-portals.md Phase 4c — the host pays the
 * admin-set quote for an approved free-registration event. plan_key =
 * 'public_registration_quote', a fourth special-cased branch alongside
 * remove_branding/enterprise/normal plans in PaymentController::initiate()
 * and PaymentCompletionService, mirroring remove_branding's shape.
 */
class PublicRegistrationPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function approvedEvent(User $owner, float $amount = 300.00): Event
    {
        return Event::factory()->for($owner)->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
            'public_registration_quote_amount' => $amount,
        ]);
    }

    public function test_edit_page_links_to_the_checkout_page_once_approved_outside_the_no_js_fallback_wrapper(): void
    {
        // Regression: the pay link was first built inside .evt-per-form-actions,
        // which public/js/event-edit-save.js unconditionally hides once it runs
        // — that class means "redundant no-JS fallback for a button the unified
        // save bar already offers", but the save bar has no pay action to fall
        // back from, so hiding it would remove the host's only way to reach it.
        $owner = User::factory()->create();
        $event = $this->approvedEvent($owner, 425.50);

        $response = $this->actingAs($owner)->get(route('events.edit', $event));

        $response->assertOk()
            ->assertSee('Pay K426 to publish', false)
            ->assertSee(route('events.public-registration.pay', $event), false);

        // The panel's own wrapper div must be a plain evt-section — not
        // evt-per-form-actions, the class event-edit-save.js hides on load.
        $this->assertMatchesRegularExpression(
            '/<div class="([^"]+)">\s*<div class="evt-section-head">\s*<h2>Publish<\/h2>/s',
            $response->getContent(),
        );
        preg_match(
            '/<div class="([^"]+)">\s*<div class="evt-section-head">\s*<h2>Publish<\/h2>/s',
            $response->getContent(),
            $matches,
        );
        $this->assertSame('evt-section', $matches[1]);
    }

    public function test_guest_cannot_view_the_checkout_page(): void
    {
        $owner = User::factory()->create();
        $event = $this->approvedEvent($owner);

        $this->get(route('events.public-registration.pay', $event))->assertRedirect('/login');
    }

    public function test_non_owner_cannot_view_the_checkout_page(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->approvedEvent($owner);

        $this->actingAs($stranger)
            ->get(route('events.public-registration.pay', $event))
            ->assertForbidden();
    }

    public function test_owner_can_view_the_checkout_page(): void
    {
        $owner = User::factory()->create();
        $event = $this->approvedEvent($owner, 300.00);

        $this->actingAs($owner)
            ->get(route('events.public-registration.pay', $event))
            ->assertOk()
            ->assertSee('K300', false);
    }

    public function test_visiting_checkout_for_a_draft_event_redirects_back(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $this->actingAs($owner)
            ->get(route('events.public-registration.pay', $event))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHas('status', 'public-registration-not-payable');
    }

    public function test_visiting_checkout_for_an_already_paid_event_redirects_back(): void
    {
        $owner = User::factory()->create();
        $event = $this->approvedEvent($owner)->fresh();
        $event->forceFill([
            'is_published' => true,
            'public_registration_quote_paid_at' => now(),
        ])->save();

        $this->actingAs($owner)
            ->get(route('events.public-registration.pay', $event))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHas('status', 'public-registration-not-payable');
    }

    public function test_initiate_requires_event_id(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'public_registration_quote',
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_initiate_rejects_an_event_owned_by_someone_else(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->approvedEvent($owner);

        $this->actingAs($stranger)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'public_registration_quote',
                'event_id' => $event->id,
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_initiate_rejects_an_event_still_in_draft(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $this->actingAs($owner)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'public_registration_quote',
                'event_id' => $event->id,
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_initiate_rejects_an_already_paid_event(): void
    {
        $owner = User::factory()->create();
        $event = $this->approvedEvent($owner)->fresh();
        $event->forceFill([
            'is_published' => true,
            'public_registration_quote_paid_at' => now(),
        ])->save();

        $this->actingAs($owner)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'public_registration_quote',
                'event_id' => $event->id,
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_successful_payment_publishes_the_event_without_touching_credits_or_tier(): void
    {
        $owner = User::factory()->withoutCredits()->create(['phone' => '0971234567']);
        $event = $this->approvedEvent($owner, 300.00);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('generatePaymentReference')->once()->andReturn('EH-registration-ref');
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_registration_1',
            'reference' => 'EH-registration-ref',
            'lencoReference' => 'LEN-REG-1',
            'status' => 'successful',
            'amount' => 300.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->actingAs($owner)->postJson(route('payment.initiate'), [
            'plan_key' => 'public_registration_quote',
            'event_id' => $event->id,
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', 'completed');

        $fresh = $event->fresh();
        $this->assertTrue($fresh->is_published);
        $this->assertNotNull($fresh->public_registration_quote_paid_at);
        $this->assertSame(0, $owner->fresh()->event_credits);
        // withoutCredits() sets the owner's tier to 'none' — must stay
        // exactly that; public_registration_quote grants neither credits nor a tier.
        $this->assertSame('none', $owner->fresh()->subscription_tier->value);

        $this->assertDatabaseHas('payments', [
            'payment_reference' => 'EH-registration-ref',
            'plan_key' => 'public_registration_quote',
            'credits_granted' => 0,
            'status' => 'completed',
        ]);
    }

    public function test_reversal_unpublishes_and_clears_paid_at_without_touching_credits(): void
    {
        $owner = User::factory()->withoutCredits()->create();
        $event = $this->approvedEvent($owner)->fresh();
        $event->forceFill([
            'is_published' => true,
            'public_registration_quote_paid_at' => now(),
        ])->save();

        $payment = Payment::factory()->for($owner)->create([
            'plan_key' => 'public_registration_quote',
            'credits_granted' => 0,
            'status' => 'completed',
            'credits_fulfilled_at' => now(),
            'metadata' => ['event_id' => $event->id],
        ]);

        app(PaymentCompletionService::class)->reverse($payment, 'failed');

        $fresh = $event->fresh();
        $this->assertFalse($fresh->is_published);
        $this->assertNull($fresh->public_registration_quote_paid_at);
        $this->assertSame(PublicRegistrationStatus::Approved, $fresh->public_registration_status);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(0, $owner->fresh()->event_credits);
        $this->assertDatabaseCount('credit_transactions', 0);
    }
}
