<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletShowTest extends TestCase
{
    use RefreshDatabase;

    private function paidTicket(): Ticket
    {
        $event = Event::factory()->ticketed()->create([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
        $type = TicketType::factory()->for($event)->create(['price' => '200.00']);
        $order = TicketOrder::factory()->for($event)->paid()->create();
        $item = TicketOrderItem::factory()->for($order, 'order')->for($type)->create();

        return Ticket::factory()->create([
            'ticket_order_id' => $order->id,
            'ticket_order_item_id' => $item->id,
            'event_id' => $event->id,
            'ticket_type_id' => $type->id,
        ]);
    }

    public function test_valid_token_returns_ticket_details_with_no_auth_required(): void
    {
        $ticket = $this->paidTicket();

        // No Authorization header at all — same token-only trust model as web.
        $response = $this->getJson(route('api.v1.tickets.wallet.show', ['token' => $ticket->public_token]));

        $response->assertOk()
            ->assertJsonPath('public_token', $ticket->public_token)
            ->assertJsonPath('attendee_name', $ticket->attendee_name)
            ->assertJsonPath('wallet_qr_payload_url', $ticket->publicUrl())
            ->assertJsonPath('download_url', route('api.v1.tickets.wallet.download', ['token' => $ticket->public_token]));
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->getJson(route('api.v1.tickets.wallet.show', ['token' => 'does-not-exist']))
            ->assertNotFound();
    }
}
