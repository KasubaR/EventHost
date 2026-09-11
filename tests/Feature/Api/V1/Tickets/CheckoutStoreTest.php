<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Enums\TicketOrderStatus;
use App\Enums\TicketReservationStatus;
use App\Jobs\RetryLencoTicketPayment;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketReservation;
use App\Models\TicketType;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CheckoutStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    private function holdCartId(Event $event, TicketType $type, int $quantity = 1): string
    {
        $hold = $this->postJson(route('api.v1.tickets.hold', ['slug' => $event->slug]), [
            'quantities' => [$type->id => $quantity],
        ])->json();

        return $hold['cart_id'];
    }

    private function buyerPayload(string $cartId): array
    {
        return [
            'cart_id' => $cartId,
            'name' => 'Jane Buyer',
            'email' => 'jane@example.com',
            'phone' => '0961234567',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ];
    }

    public function test_happy_path_returns_200_not_201_with_order_reference(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);
        $cartId = $this->holdCartId($event, $type);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_api_1',
            'lencoReference' => 'LEN-API-1',
            'status' => 'successful',
            'amount' => 200.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('api.v1.tickets.checkout.store', ['slug' => $event->slug]), $this->buyerPayload($cartId));

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['status', 'order_reference', 'payment_instructions', 'bank_details', 'payment_url']);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
    }

    public function test_missing_cart_id_fails_validation(): void
    {
        $event = $this->approvedTicketedEvent();

        $payload = $this->buyerPayload('');
        unset($payload['cart_id']);

        $this->postJson(route('api.v1.tickets.checkout.store', ['slug' => $event->slug]), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cart_id');
    }

    public function test_expired_hold_returns_422_from_the_purchase_exception(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);
        $cartId = $this->holdCartId($event, $type);

        TicketReservation::query()->where('cart_id', $cartId)->update(['expires_at' => now()->subMinute()]);

        $this->postJson(route('api.v1.tickets.checkout.store', ['slug' => $event->slug]), $this->buyerPayload($cartId))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your ticket hold has expired. Please choose your tickets again.');
    }

    public function test_missing_phone_is_rejected(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);
        $cartId = $this->holdCartId($event, $type);

        $payload = $this->buyerPayload($cartId);
        unset($payload['phone']);

        $this->postJson(route('api.v1.tickets.checkout.store', ['slug' => $event->slug]), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_lenco_server_error_queues_a_retry_and_keeps_the_order_pending(): void
    {
        Queue::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);
        $cartId = $this->holdCartId($event, $type);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Service unavailable', 503));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('api.v1.tickets.checkout.store', ['slug' => $event->slug]), $this->buyerPayload($cartId))
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::PendingPayment, $order->status);
        Queue::assertPushed(RetryLencoTicketPayment::class);
    }

    public function test_lenco_client_error_fails_the_order_and_releases_the_hold(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);
        $cartId = $this->holdCartId($event, $type);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Invalid phone', 422));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('api.v1.tickets.checkout.store', ['slug' => $event->slug]), $this->buyerPayload($cartId))
            ->assertStatus(422);

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(TicketOrderStatus::Failed, $order->status);
        $this->assertSame(
            TicketReservationStatus::Released,
            TicketReservation::query()->where('ticket_order_id', $order->id)->first()->status,
        );
    }
}
