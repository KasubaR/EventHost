<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Enums\TicketOrderStatus;
use App\Enums\TicketReservationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\TicketPurchaseException;
use App\Jobs\RetryLencoTicketPayment;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketReservation;
use App\Models\TicketType;
use App\Models\User;
use App\Services\LencoService;
use App\Services\TicketOrderFulfillmentService;
use App\Services\TicketPaymentStatusService;
use App\Services\TicketReconciliationService;
use App\Services\TicketReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class TicketPurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $apiSecret = 'test-ticket-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lenco.api_secret_key' => $this->apiSecret]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    public function test_picker_404s_for_events_that_are_not_approved_ticketed_events(): void
    {
        $draft = Event::factory()->ticketed()->create(['is_published' => true, 'ticketing_status' => TicketingStatus::Draft]);
        $this->get(route('events.public.tickets', $draft->slug))->assertNotFound();

        $invitationEvent = Event::factory()->create(['is_published' => true, 'is_public' => true]);
        $this->get(route('events.public.tickets', $invitationEvent->slug))->assertNotFound();
    }

    public function test_picker_shows_active_ticket_types_for_an_approved_event(): void
    {
        $event = $this->approvedTicketedEvent();
        TicketType::factory()->for($event)->create(['name' => 'General Admission', 'price' => '200.00']);

        $this->get(route('events.public.tickets', $event->slug))
            ->assertOk()
            ->assertSee('General Admission', false)
            ->assertSee('Qty', false)
            ->assertSee('value="1"', false);
    }

    public function test_full_flow_absorb_commission_issues_tickets_and_pays_host_amount_minus_commission(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent(['commission_mode' => CommissionMode::Absorb]);
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);

        // Session (array driver in tests) persists across these sequential
        // calls, so the cart id the hold step creates is the same one the
        // checkout step reads back — exactly the real browser flow.
        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 2],
        ])->assertRedirect(route('events.public.tickets.checkout', $event->slug));

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ticket_1',
            'lencoReference' => 'LEN-T1',
            'status' => 'successful',
            'amount' => 400.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Jane Buyer',
            'email' => 'jane@example.com',
            'phone' => '0961234567',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertSame('400.00', (string) $order->face_value);
        $this->assertSame('5.00', (string) $order->commission_percent);
        $this->assertSame(CommissionMode::Absorb, $order->commission_mode);
        $this->assertSame('20.00', (string) $order->commission_amount);
        $this->assertSame('0.00', (string) $order->buyer_fee);
        $this->assertSame('380.00', (string) $order->host_amount);
        $this->assertSame('400.00', (string) $order->buyer_total);
        $this->assertSame('400.00', (string) $order->ticket_price);
        $this->assertSame('5.00', (string) $order->commission_rate);
        $this->assertSame('380.00', (string) $order->organizer_earnings);
        $this->assertSame('400.00', (string) $order->total_paid);
        $this->assertCount(2, $order->tickets);
        $this->assertSame(2, $type->fresh()->soldQuantity());
    }

    public function test_checkout_uses_the_events_negotiated_commission_override_not_the_platform_default(): void
    {
        Notification::fake();

        // Platform default stays 5% (TicketingSettings' seeded default); this
        // event negotiated a lower rate, so checkout must snapshot 8%, not it.
        $event = $this->approvedTicketedEvent([
            'commission_mode' => CommissionMode::Absorb,
            'commission_percent_override' => '8.00',
        ]);
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);

        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ])->assertRedirect(route('events.public.tickets.checkout', $event->slug));

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ticket_override',
            'lencoReference' => 'LEN-T-OVERRIDE',
            'status' => 'successful',
            'amount' => 200.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Jane Buyer',
            'email' => 'jane@example.com',
            'phone' => '0961234567',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('200.00', (string) $order->face_value);
        $this->assertSame('8.00', (string) $order->commission_percent);
        $this->assertSame('16.00', (string) $order->commission_amount);
        $this->assertSame('184.00', (string) $order->host_amount);
    }

    public function test_pass_through_commission_adds_fee_to_buyer_total_and_pays_host_full_face_value(): void
    {
        $event = $this->approvedTicketedEvent(['commission_mode' => CommissionMode::PassThrough]);
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]])
            ->assertRedirect(route('events.public.tickets.checkout', $event->slug));

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ticket_2',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Pass Through Buyer',
            'email' => 'pt@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ]);

        $response->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('200.00', (string) $order->face_value);
        $this->assertSame('10.00', (string) $order->commission_amount);
        $this->assertSame('10.00', (string) $order->buyer_fee);
        $this->assertSame('210.00', (string) $order->buyer_total);
        $this->assertSame('200.00', (string) $order->host_amount);
        $this->assertSame(CommissionMode::PassThrough, $order->commission_mode);
    }

    public function test_hold_rejects_when_quantity_exceeds_capacity(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 1]);

        // First buyer takes the only seat.
        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ])->assertRedirect(route('events.public.tickets.checkout', $event->slug));

        // A second, independent browser session tries for the same (now gone) seat.
        $this->flushSession();
        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ])->assertRedirect(route('events.public.tickets', $event->slug))
            ->assertSessionHasErrors('tickets');

        $this->assertSame(1, TicketReservation::query()->where('ticket_type_id', $type->id)->count());
    }

    public function test_expired_reservations_free_capacity_for_the_next_buyer(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 1]);

        TicketReservation::factory()->for($event)->for($type, 'ticketType')->expired()->create([
            'quantity' => 1,
            'unit_price_snapshot' => '200.00',
        ]);

        $this->artisan('tickets:expire-reservations')->assertSuccessful();

        $this->assertSame(
            TicketReservationStatus::Expired,
            TicketReservation::query()->where('ticket_type_id', $type->id)->first()->status,
        );

        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ])->assertRedirect(route('events.public.tickets.checkout', $event->slug));
    }

    public function test_webhook_completes_ticket_order_and_is_idempotent_on_replay(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 10]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_webhook_ticket',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Webhook Buyer',
            'email' => 'webhook@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->status);

        // The mock above only stubs initiateMobileMoneyPayment — the webhook
        // path needs the real validateWebhookSignature(), so drop back to the
        // real LencoService for it (mirrors PaymentWebhookTest's approach of
        // never mocking Lenco for the webhook itself).
        $this->app->forgetInstance(LencoService::class);

        $payload = json_encode([
            'data' => [
                'id' => 'col_webhook_ticket',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => (float) $order->buyer_total,
                'currency' => 'ZMW',
            ],
        ]);

        $this->postSignedWebhook($payload)->assertOk();
        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertCount(1, $order->tickets);
    }

    public function test_picker_404s_when_event_is_not_public(): void
    {
        $event = $this->approvedTicketedEvent(['is_public' => false]);
        TicketType::factory()->for($event)->create(['price' => '200.00']);

        $this->get(route('events.public.tickets', $event->slug))->assertNotFound();
    }

    public function test_holds_tied_to_a_pending_order_keep_capacity_after_the_original_expiry(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_inflight',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Slow Buyer',
            'email' => 'slow@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $reservation = TicketReservation::query()->where('ticket_type_id', $type->id)->firstOrFail();
        $reservation->forceFill(['expires_at' => now()->subMinutes(20)])->save();

        $this->artisan('tickets:expire-reservations')->assertSuccessful();

        $reservation->refresh();
        $this->assertSame(TicketReservationStatus::Held, $reservation->status);
        $this->assertNotNull($reservation->ticket_order_id);
        $this->assertSame(0, $type->fresh()->availableQuantity());

        $this->flushSession();
        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ])->assertRedirect(route('events.public.tickets', $event->slug))
            ->assertSessionHasErrors('tickets');
    }

    public function test_complete_does_not_issue_tickets_for_a_cancelled_order(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 5]);
        $order = TicketOrder::factory()->for($event)->create([
            'status' => TicketOrderStatus::Cancelled,
        ]);
        $order->items()->create([
            'ticket_type_id' => $type->id,
            'ticket_type_name' => $type->name,
            'unit_price' => '200.00',
            'quantity' => 1,
            'subtotal' => '200.00',
        ]);

        app(TicketOrderFulfillmentService::class)->complete($order);

        $this->assertSame(TicketOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertCount(0, $order->fresh()->tickets);
    }

    public function test_lenco_server_error_queues_retry_and_keeps_the_hold(): void
    {
        Queue::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 2]);

        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Service unavailable', 503));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Queued Buyer',
            'email' => 'queued@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk()->assertJsonPath('success', true);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->status);
        $this->assertDatabaseHas('ticket_payments', [
            'ticket_order_id' => $order->id,
            'status' => 'pending',
        ]);
        $this->assertSame(TicketReservationStatus::Held, TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status);
        Queue::assertPushed(RetryLencoTicketPayment::class);
    }

    public function test_webhook_retries_fulfillment_when_payment_is_already_completed(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 10]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_retry_fulfill',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Retry Buyer',
            'email' => 'retry@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $order->payment->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertCount(0, $order->tickets);

        $this->app->forgetInstance(LencoService::class);

        $payload = json_encode([
            'data' => [
                'id' => 'col_retry_fulfill',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => (float) $order->buyer_total,
                'currency' => 'ZMW',
            ],
        ]);

        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertCount(1, $order->tickets);
    }

    public function test_order_moves_to_payment_processing_and_still_completes_afterward(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 10]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_processing',
            'status' => 'processing',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Processing Buyer',
            'email' => 'processing@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        // A payment actively being collected must still block deletion, the
        // cart re-holding, and a second concurrent checkout — same as
        // pending_payment. See Event::hasBlockingTicketCommerce() and the
        // in-progress guards in TicketReservationService/TicketCheckoutService.
        $this->assertSame(TicketOrderStatus::PaymentProcessing, $order->status);
        $this->assertTrue($event->fresh()->hasBlockingTicketCommerce());

        $this->get(route('ticket.orders.show', $order->order_reference))
            ->assertOk()
            ->assertSee('Approve the payment on your phone', false);

        $this->app->forgetInstance(LencoService::class);

        $payload = json_encode([
            'data' => [
                'id' => 'col_processing',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => (float) $order->buyer_total,
                'currency' => 'ZMW',
            ],
        ]);

        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertCount(1, $order->tickets);
    }

    public function test_payment_failure_marks_the_order_failed_not_cancelled_and_frees_capacity(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_failed',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Failing Buyer',
            'email' => 'failing@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->app->forgetInstance(LencoService::class);

        $payload = json_encode([
            'data' => [
                'id' => 'col_failed',
                'reference' => $order->order_reference,
                'status' => 'failed',
            ],
        ]);

        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Failed, $order->status);
        $this->assertSame(1, $type->fresh()->availableQuantity());
        $this->assertSame(
            TicketReservationStatus::Released,
            TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status,
        );
    }

    public function test_payment_cancelled_at_provider_marks_the_order_cancelled(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_cancelled',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Cancelling Buyer',
            'email' => 'cancelling@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->app->forgetInstance(LencoService::class);

        $payload = json_encode([
            'data' => [
                'id' => 'col_cancelled',
                'reference' => $order->order_reference,
                'status' => 'cancelled',
            ],
        ]);

        $this->postSignedWebhook($payload)->assertOk();

        $this->assertSame(TicketOrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_initiate_failed_status_fails_the_order_and_releases_capacity(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_initiate_failed',
            'status' => 'failed',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Initiate Fail Buyer',
            'email' => 'initiate-fail@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::Failed, $order->status);
        $this->assertSame('failed', $order->payment->status);
        $this->assertSame(1, $type->fresh()->availableQuantity());
        $this->assertSame(
            TicketReservationStatus::Released,
            TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status,
        );
    }

    public function test_checkout_client_error_marks_payment_and_order_failed(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Invalid phone', 422));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Client Error Buyer',
            'email' => 'client-error@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertStatus(422);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::Failed, $order->status);
        $this->assertSame('failed', $order->payment->status);
        $this->assertSame(
            TicketReservationStatus::Released,
            TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status,
        );
    }

    public function test_retry_job_exhaustion_fails_the_order_and_releases_holds(): void
    {
        Queue::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Service unavailable', 503));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Retry Exhaust Buyer',
            'email' => 'retry-exhaust@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $payment = $order->payment;

        $this->app->forgetInstance(LencoService::class);
        $retryLenco = Mockery::mock(LencoService::class);
        $retryLenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Service unavailable', 503));
        $this->app->instance(LencoService::class, $retryLenco);

        $job = new RetryLencoTicketPayment($payment->fresh());
        $job->tries = 1;

        try {
            $job->handle(app(LencoService::class), app(TicketPaymentStatusService::class));
        } catch (\RuntimeException $e) {
            $this->assertSame('Service unavailable', $e->getMessage());
        }

        $this->assertSame(TicketOrderStatus::Failed, $order->fresh()->status);
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(
            TicketReservationStatus::Released,
            TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status,
        );
        $this->assertSame(1, $type->fresh()->availableQuantity());
    }

    public function test_poller_max_age_expires_the_ticket_order_and_releases_holds(): void
    {
        config(['services.lenco.pending_max_age_hours' => 24]);

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_poll_expire',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Poll Expire Buyer',
            'email' => 'poll-expire@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $order->payment->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->app->forgetInstance(LencoService::class);
        $idleLenco = Mockery::mock(LencoService::class);
        $idleLenco->shouldNotReceive('verifyPayment');
        $idleLenco->shouldNotReceive('verifyByReference');
        $this->app->instance(LencoService::class, $idleLenco);

        $this->artisan('tickets:poll-pending')->assertSuccessful();

        $this->assertSame(TicketOrderStatus::Expired, $order->fresh()->status);
        $this->assertSame('cancelled', $order->payment->fresh()->status);
        $this->assertSame(
            TicketReservationStatus::Released,
            TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status,
        );
        $this->assertSame(1, $type->fresh()->availableQuantity());
    }

    public function test_completed_webhook_after_cancel_recovers_and_issues_tickets(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_recover',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Recover Buyer',
            'email' => 'recover@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->app->forgetInstance(LencoService::class);

        $cancelPayload = json_encode([
            'data' => [
                'id' => 'col_recover',
                'reference' => $order->order_reference,
                'status' => 'cancelled',
            ],
        ]);
        $this->postSignedWebhook($cancelPayload)->assertOk();
        $this->assertSame(TicketOrderStatus::Cancelled, $order->fresh()->status);

        $completePayload = json_encode([
            'data' => [
                'id' => 'col_recover',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => (float) $order->buyer_total,
                'currency' => 'ZMW',
            ],
        ]);
        $this->postSignedWebhook($completePayload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertCount(1, $order->tickets);
        $this->assertSame('completed', $order->payment->status);
    }

    public function test_owner_cannot_delete_event_with_a_pending_ticket_order(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
        TicketOrder::factory()->for($event)->create();

        $this->actingAs($user)
            ->from(route('events.show', $event))
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_owner_cannot_delete_ticket_type_with_an_active_hold(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 5]);
        TicketReservation::factory()->for($event)->for($type, 'ticketType')->create();

        $this->actingAs($user)
            ->delete(route('events.ticket-types.destroy', ['event' => $event, 'ticketType' => $type]))
            ->assertRedirect(route('events.ticket-types.index', $event))
            ->assertSessionHasErrors('ticket_type');

        $this->assertDatabaseHas('ticket_types', ['id' => $type->id]);
    }

    /**
     * "Last ticket purchased" — the difficult-case list, not a new scenario:
     * exercises the full flow (hold -> checkout -> webhook) against a type
     * with exactly one seat left, and confirms it's actually sold out
     * afterward (not just held), including a further hold attempt bouncing.
     */
    public function test_last_available_ticket_completes_the_full_purchase_and_marks_sold_out(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 3]);
        // Two seats already sold — one left.
        Ticket::factory()->count(2)->for($type, 'ticketType')->for($event)->create(['status' => TicketStatus::Valid]);
        $this->assertSame(1, $type->fresh()->availableQuantity());

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_last_ticket',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Last Buyer',
            'email' => 'last@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->app->forgetInstance(LencoService::class);

        $payload = json_encode([
            'data' => [
                'id' => 'col_last_ticket',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => (float) $order->buyer_total,
                'currency' => 'ZMW',
            ],
        ]);
        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertCount(1, $order->tickets);
        $this->assertSame(3, $type->fresh()->soldQuantity());
        $this->assertSame(0, $type->fresh()->availableQuantity());

        // The type is genuinely sold out now, not just "held" — a fresh
        // buyer bounces immediately.
        $this->flushSession();
        $this->post(route('events.public.tickets.hold', $event->slug), [
            'quantities' => [$type->id => 1],
        ])->assertRedirect(route('events.public.tickets', $event->slug))
            ->assertSessionHasErrors('tickets');
    }

    /**
     * "Two people buying the last ticket simultaneously" — TicketReservationService::
     * hold() takes TicketType::lockForUpdate() before computing availableQuantity(),
     * inside one DB transaction per call. Under real concurrent load (MySQL),
     * that row lock serializes two simultaneous requests into exactly the
     * sequence exercised here: whichever hold() commits first wins the seat,
     * and the second call only proceeds — re-reading live, post-commit
     * capacity — once the first transaction has released the lock. This
     * test proves the outcome that guarantee produces; true parallel-thread
     * execution against two live connections isn't exercisable against the
     * single-connection SQLite `:memory:` test database (phpunit.xml), so
     * the row-lock's serialization itself is asserted by reading
     * TicketReservationService::hold() rather than reproduced here.
     */
    public function test_two_buyers_racing_for_the_last_ticket_only_one_wins_the_hold(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 1]);

        $service = app(TicketReservationService::class);

        $winning = $service->hold($event, 'cart-a', [$type->id => 1]);
        $this->assertCount(1, $winning);

        try {
            $service->hold($event, 'cart-b', [$type->id => 1]);
            $this->fail('Expected the second, losing buyer to be rejected — the seat is already held.');
        } catch (TicketPurchaseException $e) {
            $this->assertStringContainsString('sold out', strtolower($e->getMessage()));
        }

        $this->assertSame(0, $type->fresh()->availableQuantity());
        $this->assertSame(
            1,
            TicketReservation::query()->where('ticket_type_id', $type->id)->where('status', TicketReservationStatus::Held)->count(),
        );
        // The loser's cart never got a row at all — hold() throws before
        // creating anything for the type it rejected.
        $this->assertSame(0, TicketReservation::query()->where('cart_id', 'cart-b')->count());
    }

    /**
     * "Reservation payment succeeds at expiry" — extends
     * test_holds_tied_to_a_pending_order_keep_capacity_after_the_original_expiry
     * (which only proves the sweep leaves the hold alone) through to an
     * actual late payment success, confirming the order still completes
     * correctly and the reservation converts rather than staying stuck.
     */
    public function test_reservation_held_past_its_nominal_expiry_still_completes_once_payment_confirms(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_late_success',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Slow But Paying Buyer',
            'email' => 'slowpay@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $reservation = TicketReservation::query()->where('ticket_order_id', $order->id)->firstOrFail();

        // Mobile money approval took longer than the 10-minute hold window.
        $reservation->forceFill(['expires_at' => now()->subMinutes(20)])->save();

        // The sweep runs mid-checkout (e.g. the scheduler firing) and
        // correctly leaves this hold alone — it's tied to an in-flight order.
        $this->artisan('tickets:expire-reservations')->assertSuccessful();
        $this->assertSame(TicketReservationStatus::Held, $reservation->fresh()->status);

        // The buyer finally approves the prompt, well after the nominal window.
        $this->app->forgetInstance(LencoService::class);
        $payload = json_encode([
            'data' => [
                'id' => 'col_late_success',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => (float) $order->buyer_total,
                'currency' => 'ZMW',
            ],
        ]);
        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertCount(1, $order->tickets);
        $this->assertSame(TicketReservationStatus::Converted, $reservation->fresh()->status);
        $this->assertSame(0, $type->fresh()->availableQuantity());
    }

    /**
     * "Payment remains pending" — the stable middle state, distinct from
     * test_poller_max_age_expires_the_ticket_order_and_releases_holds (the
     * eventual 24h force-fail). A payment that is simply still pending must:
     * keep occupying its seat (nobody else can take it), issue nothing, stay
     * untouched by a poll run inside the poller's own 2-minute head start,
     * and not be misreported as "stuck" by Phase 24's reconciliation check
     * (that threshold is 2 hours, not "any pending payment").
     */
    public function test_payment_left_pending_blocks_capacity_but_issues_nothing(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_stays_pending',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Patient Buyer',
            'email' => 'patient@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->status);
        $this->assertSame('pending', $order->payment->status);

        // Still under the poller's 2-minute head start — must not even try
        // to reach Lenco yet. $lenco only stubs initiateMobileMoneyPayment,
        // so an unexpected verify call here would fail loudly.
        $this->artisan('tickets:poll-pending')->assertSuccessful();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->status);
        $this->assertSame('pending', $order->payment->fresh()->status);
        $this->assertCount(0, $order->tickets);
        // Still occupying the only seat — nobody else can take it while
        // it's genuinely undecided.
        $this->assertSame(0, $type->fresh()->availableQuantity());

        $stuck = app(TicketReconciliationService::class)->stuckInFlightPayments();
        $this->assertCount(0, $stuck);
    }

    /**
     * "Incorrect amount" — TicketPaymentStatusService::applyVerificationResult()
     * has the exact same settlement-amount check the credit-payment webhook
     * already has a dedicated test for (PaymentWebhookTest::
     * test_webhook_fails_payment_on_amount_mismatch), but nothing exercised
     * it via the ticket webhook path before this test.
     */
    public function test_webhook_fails_ticket_order_on_amount_mismatch(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ticket_mismatch',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Mismatch Buyer',
            'email' => 'mismatch@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->app->forgetInstance(LencoService::class);

        // Order says 150.00 was due; the "provider" reports a completely
        // different amount was actually settled.
        $payload = json_encode([
            'data' => [
                'id' => 'col_ticket_mismatch',
                'reference' => $order->order_reference,
                'status' => 'successful',
                'amount' => 999.00,
                'currency' => 'ZMW',
            ],
        ]);
        $this->postSignedWebhook($payload)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Failed, $order->status);
        $this->assertSame('failed', $order->payment->fresh()->status);
        $this->assertStringContainsString('mismatch', strtolower((string) $order->payment->fresh()->failure_reason));
        $this->assertCount(0, $order->tickets);
        $this->assertSame(1, $type->fresh()->availableQuantity());
    }

    /**
     * "Payment verification failure" (poller side) — Lenco being unreachable
     * or erroring mid-poll must not crash the run or corrupt the payment;
     * it should be skipped this cycle and picked up again next time. Both
     * PollPendingTicketPayments and PollPendingPayments (credits) wrap each
     * verify call in a try/catch for exactly this, but neither had a test
     * that actually made the mock throw before this one.
     */
    public function test_ticket_poller_survives_a_lenco_verification_error(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_verify_error',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Flaky Network Buyer',
            'email' => 'flaky@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $order->payment->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $lenco->shouldReceive('verifyPayment')->once()->with('col_verify_error')
            ->andThrow(new \RuntimeException('Lenco is unreachable', 503));

        $this->artisan('tickets:poll-pending')->assertSuccessful();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->status);
        $this->assertSame('pending', $order->payment->fresh()->status);
        $this->assertSame(0, $type->fresh()->availableQuantity());
    }

    /**
     * "Payment verification failure" (buyer side) — the order-status page's
     * "check now" button hits this endpoint. Neither the success path nor
     * the Lenco-error path had any test before this pair; a buyer clicking
     * that button during a Lenco outage is exactly the failure mode a
     * missing api_secret_key (mentioned when this round of testing was
     * requested) would also produce in production until it's configured.
     */
    public function test_buyer_verify_completes_the_order_via_lenco(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_buyer_verify',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Impatient Buyer',
            'email' => 'impatient@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();

        $lenco->shouldReceive('verifyByReference')->once()->with($order->order_reference)->andReturn([
            'transactionId' => 'col_buyer_verify',
            'lencoStatus' => 'successful',
            'status' => 'completed',
            'amount' => (float) $order->buyer_total,
            'currency' => 'ZMW',
            'rawResponse' => [],
        ]);

        $response = $this->getJson(route('ticket.orders.verify', $order->order_reference));

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('status', TicketOrderStatus::Paid->value);
        $this->assertSame(TicketOrderStatus::Paid, $order->fresh()->status);
        $this->assertCount(1, $order->fresh()->tickets);
    }

    public function test_buyer_verify_returns_502_when_lenco_verification_fails(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '150.00', 'quantity' => 1]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 1]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_buyer_verify_fail',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Unlucky Buyer',
            'email' => 'unlucky@example.com',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();

        $lenco->shouldReceive('verifyByReference')->once()->with($order->order_reference)
            ->andThrow(new \RuntimeException('Lenco API key is not configured.', 500));

        $response = $this->getJson(route('ticket.orders.verify', $order->order_reference));

        $response->assertStatus(502)->assertJsonPath('success', false)->assertJsonPath('message', 'Lenco API key is not configured.');
        // The buyer sees a clear "try again" error, and nothing about the
        // order or its seat changed — it's still exactly where it was.
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment->status);
        $this->assertSame(0, $type->fresh()->availableQuantity());
    }

    private function postSignedWebhook(string $payload)
    {
        $hashKey = hash('sha256', $this->apiSecret);
        $signature = hash_hmac('sha512', $payload, $hashKey);

        return $this->call(
            'POST',
            route('lenco.webhook'),
            [],
            [],
            [],
            [
                'HTTP_X-Lenco-Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        );
    }
}
