<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\TicketOrder;
use App\Models\TicketPayment;
use App\Models\TicketType;
use App\Models\User;
use App\Services\AstragateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AstragateCardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const CALLBACK = '/webhooks/astragate/hush-hush-secret';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'astragate.card_enabled' => true,
            'astragate.client_id' => 'cid',
            'astragate.client_secret' => 'csecret',
            'astragate.webhook_secret' => 'hush-hush-secret',
        ]);
    }

    private function fakeAstragate(int $statusCode = 4001, int $sessionHttp = 200): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'auth.dev.astragate.africa/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.dev.astragate.africa/v1/payment/checkout-sessions' => $sessionHttp === 200
                ? Http::response([
                    'success' => 'true',
                    'message' => 'SUCCESS',
                    'data' => ['checkoutUrl' => 'https://checkout.dev.astragate.africa/r/checkout?session=agt-cs_1', 'sessionId' => 'agt-cs_1', 'token' => 'jwt'],
                ])
                : Http::response(['success' => false, 'message' => 'Gateway down'], $sessionHttp),
            'api.dev.astragate.africa/v1/payment/status/*' => Http::response([
                'success' => true,
                'data' => ['statusCode' => $statusCode, 'astragateTransactionId' => 'agt-tx-1'],
            ]),
        ]);
    }

    /** @return array{0: User, 1: Payment} */
    private function startCardBilling(): array
    {
        $user = User::factory()->withoutCredits()->create();
        $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'base',
            'payment_method' => 'card',
        ])->assertOk()->assertJsonPath('success', true);

        return [$user, Payment::query()->where('user_id', $user->id)->firstOrFail()];
    }

    private function sendCallback(string $reference, int $statusCode = 4200): TestResponse
    {
        return $this->postJson(self::CALLBACK, [
            'callbackType' => 'CHECKOUT',
            'statusCode' => (string) $statusCode,
            'correlatorId' => $reference,
        ]);
    }

    public function test_status_codes_map_to_payment_statuses(): void
    {
        $this->assertSame('completed', AstragateService::mapStatusCode(4200));
        $this->assertSame('pending', AstragateService::mapStatusCode('4001'));
        $this->assertSame('failed', AstragateService::mapStatusCode(4011));
        $this->assertSame('cancelled', AstragateService::mapStatusCode(4007));
        $this->assertSame('pending', AstragateService::mapStatusCode(null));
    }

    public function test_card_is_rejected_while_disabled(): void
    {
        $this->fakeAstragate();
        config(['astragate.card_enabled' => false]);
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)->postJson(route('payment.initiate'), ['plan_key' => 'base', 'payment_method' => 'card'])
            ->assertStatus(422);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_card_is_rejected_without_credentials(): void
    {
        $this->fakeAstragate();
        config(['astragate.client_secret' => '']);
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)->postJson(route('payment.initiate'), ['plan_key' => 'base', 'payment_method' => 'card'])
            ->assertStatus(422);
    }

    public function test_billing_card_initiate_creates_an_astragate_row_and_returns_the_checkout_url(): void
    {
        $this->fakeAstragate();
        $user = User::factory()->withoutCredits()->create();

        $response = $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'base',
            'payment_method' => 'card',
        ]);

        $payment = Payment::query()->firstOrFail();
        $response->assertOk()
            ->assertJsonPath('payment_url', 'https://checkout.dev.astragate.africa/r/checkout?session=agt-cs_1&token=jwt')
            ->assertJsonPath('payment_reference', $payment->payment_reference);
        $this->assertSame('astragate', $payment->gateway);
        $this->assertSame('card', $payment->payment_method);
        $this->assertSame('agt-cs_1', $payment->checkout_session_id);
        $this->assertSame('pending', $payment->status);
        // The session token is in the link, never in the stored response.
        $this->assertStringNotContainsString('jwt', json_encode($payment->lenco_response));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payment/checkout-sessions')
            && $r['paymentMode'] === 'CARD'
            && $r['correlatorId'] === $payment->payment_reference
            && (float) $r['lineItems'][0]['unitPrice'] === (float) $payment->amount);
    }

    public function test_billing_card_initiate_reports_a_gateway_failure_and_stores_nothing(): void
    {
        $this->fakeAstragate(sessionHttp: 503);
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)->postJson(route('payment.initiate'), ['plan_key' => 'base', 'payment_method' => 'card'])
            ->assertStatus(503)->assertJsonPath('success', false);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_callback_completes_a_billing_payment_once(): void
    {
        $this->fakeAstragate(4200);
        [$user, $payment] = $this->startCardBilling();

        $this->sendCallback($payment->payment_reference)->assertOk();
        $this->sendCallback($payment->payment_reference)->assertOk();

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertTrue($payment->webhook_received);
        $this->assertSame('agt-tx-1', $payment->lenco_transaction_id);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_callback_body_is_not_trusted(): void
    {
        // The callback claims success; Astragate's own status says still processing.
        $this->fakeAstragate(4001);
        [$user, $payment] = $this->startCardBilling();

        $this->sendCallback($payment->payment_reference, 4200)->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_failed_card_payment_is_marked_failed(): void
    {
        $this->fakeAstragate(4011);
        [$user, $payment] = $this->startCardBilling();

        $this->sendCallback($payment->payment_reference, 4011)->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_callback_never_moves_a_lenco_payment(): void
    {
        $this->fakeAstragate(4200);
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create(['status' => 'pending']);

        $this->sendCallback($payment->payment_reference)->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->event_credits);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/payment/status/'));
    }

    public function test_unknown_reference_is_acknowledged(): void
    {
        $this->fakeAstragate();

        $this->sendCallback('EH-0-0-nothing')->assertOk()->assertJsonPath('message', 'acknowledged');
    }

    public function test_callback_with_wrong_secret_is_a_404(): void
    {
        $this->postJson('/webhooks/astragate/wrong', ['correlatorId' => 'x', 'statusCode' => '4200'])->assertNotFound();
    }

    public function test_callback_is_a_404_when_no_secret_is_configured(): void
    {
        config(['astragate.webhook_secret' => '']);

        $this->postJson('/webhooks/astragate/anything', ['correlatorId' => 'x'])->assertNotFound();
    }

    public function test_verify_endpoint_asks_astragate_for_a_card_payment(): void
    {
        $this->fakeAstragate(4200);
        [$user, $payment] = $this->startCardBilling();

        $this->actingAs($user)->getJson(route('payment.verify.ref', $payment->payment_reference))
            ->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame(1, $user->fresh()->event_credits);
    }

    private function approvedTicketedEvent(): Event
    {
        return Event::factory()->ticketed()->create([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
    }

    private function holdTickets(Event $event, int $quantity = 2): void
    {
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);
        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => $quantity]])
            ->assertRedirect(route('events.public.tickets.checkout', $event->slug));
    }

    /** @return array<string, string> */
    private function buyer(): array
    {
        return ['name' => 'Jane Buyer', 'email' => 'jane@example.com', 'phone' => '0961234567', 'payment_method' => 'card'];
    }

    public function test_ticket_card_checkout_redirects_to_astragate_then_callback_issues_tickets(): void
    {
        Notification::fake();
        $this->fakeAstragate(4200);
        $event = $this->approvedTicketedEvent();
        $this->holdTickets($event);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), $this->buyer())
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', TicketOrderStatus::PendingPayment->value)
            ->assertJsonPath('payment_url', 'https://checkout.dev.astragate.africa/r/checkout?session=agt-cs_1&token=jwt');

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $payment = TicketPayment::query()->where('ticket_order_id', $order->id)->firstOrFail();
        $this->assertSame('astragate', $payment->gateway);
        $this->assertSame('card', $payment->payment_method);
        $this->assertCount(0, $order->tickets);

        $this->sendCallback($order->order_reference)->assertOk();
        $this->sendCallback($order->order_reference)->assertOk();

        $order->refresh();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertCount(2, $order->tickets);
    }

    public function test_ticket_order_page_offers_the_card_link_while_pending(): void
    {
        $this->fakeAstragate();
        $event = $this->approvedTicketedEvent();
        $this->holdTickets($event);
        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), $this->buyer())->assertOk();
        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();

        $this->get(route('ticket.orders.show', $order->order_reference))
            ->assertOk()
            ->assertSee('Continue to card payment');
    }

    public function test_ticket_card_failure_fails_the_order(): void
    {
        $this->fakeAstragate(sessionHttp: 503);
        $event = $this->approvedTicketedEvent();
        $this->holdTickets($event);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), $this->buyer())
            ->assertStatus(503)->assertJsonPath('success', false);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('failed', TicketPayment::query()->where('ticket_order_id', $order->id)->firstOrFail()->status);
        $this->assertTrue($order->fresh()->status->isTerminal());
    }
}
