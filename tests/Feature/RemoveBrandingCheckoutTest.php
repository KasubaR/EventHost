<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use App\Services\PaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The K250 "remove EventHost branding" per-event addon — see
 * plans/remove-branding.md. plan_key = 'remove_branding', a third
 * special-cased branch alongside the normal credit-granting plans and the
 * Enterprise/CustomQuote flow in PaymentController::initiate() and
 * PaymentCompletionService.
 */
class RemoveBrandingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_cannot_view_the_checkout_page(): void
    {
        $event = Event::factory()->create();

        $this->get(route('events.remove-branding', $event))->assertRedirect('/login');
    }

    public function test_non_owner_cannot_view_the_checkout_page(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->get(route('events.remove-branding', $event))
            ->assertForbidden();
    }

    public function test_owner_can_view_the_checkout_page(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('events.remove-branding', $event))
            ->assertOk()
            ->assertSee('K250', false);
    }

    public function test_visiting_checkout_for_an_already_removed_event_redirects_back(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['branding_removed' => true]);

        $this->actingAs($owner)
            ->get(route('events.remove-branding', $event))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHas('status', 'branding-already-removed');
    }

    public function test_initiate_requires_event_id_for_remove_branding(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'remove_branding',
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
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'remove_branding',
                'event_id' => $event->id,
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_initiate_rejects_an_already_removed_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['branding_removed' => true]);

        $this->actingAs($owner)
            ->postJson(route('payment.initiate'), [
                'plan_key' => 'remove_branding',
                'event_id' => $event->id,
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'phone' => '0961234567',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_successful_payment_removes_branding_without_touching_credits_or_tier(): void
    {
        $owner = User::factory()->withoutCredits()->create(['phone' => '0971234567']);
        $event = Event::factory()->for($owner)->create();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('generatePaymentReference')->once()->andReturn('EH-branding-ref');
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_branding_1',
            'reference' => 'EH-branding-ref',
            'lencoReference' => 'LEN-BR-1',
            'status' => 'successful',
            'amount' => 250.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->actingAs($owner)->postJson(route('payment.initiate'), [
            'plan_key' => 'remove_branding',
            'event_id' => $event->id,
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ]);

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', 'completed');

        $this->assertTrue($event->fresh()->branding_removed);
        $this->assertSame(0, $owner->fresh()->event_credits);
        // withoutCredits() sets the owner's tier to 'none' — must stay
        // exactly that; remove_branding grants neither credits nor a tier.
        $this->assertSame('none', $owner->fresh()->subscription_tier->value);

        $this->assertDatabaseHas('payments', [
            'payment_reference' => 'EH-branding-ref',
            'plan_key' => 'remove_branding',
            'credits_granted' => 0,
            'status' => 'completed',
        ]);
    }

    public function test_reversal_restores_branding_and_does_not_touch_credits(): void
    {
        $owner = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($owner)->create(['branding_removed' => true]);

        $payment = Payment::factory()->for($owner)->create([
            'plan_key' => 'remove_branding',
            'credits_granted' => 0,
            'status' => 'completed',
            'credits_fulfilled_at' => now(),
            'metadata' => ['event_id' => $event->id],
        ]);

        app(PaymentCompletionService::class)->reverse($payment, 'failed');

        $this->assertFalse($event->fresh()->branding_removed);
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame(0, $owner->fresh()->event_credits);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_host_bar_is_hidden_once_branding_is_removed(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'is_public' => true,
            'branding_removed' => true,
        ]);

        $response = $this->get(route('events.public', $event->slug));

        $response->assertOk();
        $response->assertDontSee('evt-host-bar', false);
        $response->assertDontSee('Get started free');
    }

    public function test_host_bar_shows_when_branding_is_not_removed(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'is_public' => true,
        ]);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Get started free');
    }
}
