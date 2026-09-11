<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketPayment;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderShowTest extends TestCase
{
    use RefreshDatabase;

    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    public function test_paid_order_returns_tickets_with_wallet_urls(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00']);
        $order = TicketOrder::factory()->for($event)->paid()->create();
        $item = TicketOrderItem::factory()->for($order, 'order')->for($type)->create();
        $ticket = Ticket::factory()->create([
            'ticket_order_id' => $order->id,
            'ticket_order_item_id' => $item->id,
            'event_id' => $event->id,
            'ticket_type_id' => $type->id,
        ]);

        $response = $this->getJson(route('api.v1.tickets.orders.show', ['orderReference' => $order->order_reference]));

        $response->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('order_reference', $order->order_reference)
            ->assertJsonPath('tickets.0.public_token', $ticket->public_token)
            ->assertJsonPath('tickets.0.wallet_qr_payload_url', $ticket->publicUrl())
            ->assertJsonPath('tickets.0.download_url', route('api.v1.tickets.wallet.download', ['token' => $ticket->public_token]));
    }

    public function test_unpaid_order_has_failure_reason_and_no_commission_fields(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->failed()->create();
        TicketPayment::factory()->for($order, 'order')->create([
            'status' => 'failed',
            'failure_reason' => 'Card declined',
        ]);

        $response = $this->getJson(route('api.v1.tickets.orders.show', ['orderReference' => $order->order_reference]));

        $response->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('failure_reason', 'Card declined');

        // TicketOrderResource deliberately never exposes internal accounting columns the
        // buyer-facing web order-status page never shows either — see the Slice B2 plan.
        $response->assertJsonMissingPath('face_value');
        $response->assertJsonMissingPath('commission_amount');
        $response->assertJsonMissingPath('commission_percent');
        $response->assertJsonMissingPath('host_amount');
        $response->assertJsonMissingPath('buyer_fee');
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $this->getJson(route('api.v1.tickets.orders.show', ['orderReference' => 'TKT-does-not-exist']))
            ->assertNotFound();
    }
}
