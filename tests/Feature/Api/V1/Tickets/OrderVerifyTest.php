<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Enums\TicketOrderStatus;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketPayment;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderVerifyTest extends TestCase
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

    public function test_success_returns_200_with_no_redirect_url_key(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::PendingPayment]);
        $payment = TicketPayment::factory()->for($order, 'order')->create([
            'status' => 'pending',
            'payment_reference' => $order->order_reference,
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyByReference')->once()->andReturn([
            'status' => 'successful',
            'amount' => (float) $order->buyer_total,
            'currency' => 'ZMW',
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->getJson(route('api.v1.tickets.orders.verify', ['orderReference' => $order->order_reference]));

        $response->assertOk()->assertJsonPath('success', true);
        // Web's twin includes redirect_url (a Blade page URL) — the API drops it, see the
        // Slice B2 plan, design decision #3.
        $response->assertJsonMissingPath('redirect_url');
    }

    public function test_lenco_error_returns_502_with_status_unchanged(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::PendingPayment]);
        TicketPayment::factory()->for($order, 'order')->create([
            'status' => 'pending',
            'payment_reference' => $order->order_reference,
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyByReference')->once()->andThrow(new \RuntimeException('Lenco unreachable', 502));
        $this->app->instance(LencoService::class, $lenco);

        $this->getJson(route('api.v1.tickets.orders.verify', ['orderReference' => $order->order_reference]))
            ->assertStatus(502)
            ->assertJsonPath('status', TicketOrderStatus::PendingPayment->value);
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $this->getJson(route('api.v1.tickets.orders.verify', ['orderReference' => 'TKT-does-not-exist']))
            ->assertNotFound();
    }

    public function test_malformed_reference_is_rejected(): void
    {
        $this->getJson(route('api.v1.tickets.orders.verify', ['orderReference' => 'bad ref!']))
            ->assertNotFound();
    }
}
