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

class WalletDownloadTest extends TestCase
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

    public function test_valid_token_downloads_a_pdf(): void
    {
        $ticket = $this->paidTicket();

        $response = $this->get(route('api.v1.tickets.wallet.download', ['token' => $ticket->public_token]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->get(route('api.v1.tickets.wallet.download', ['token' => 'does-not-exist']))
            ->assertNotFound();
    }
}
