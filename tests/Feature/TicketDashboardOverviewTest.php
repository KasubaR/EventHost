<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Ticketing section's Overview tab (Phase 15) — see
 * EventTicketDashboardController and events/tickets/overview.blade.php.
 */
class TicketDashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_shows_sold_remaining_checked_in_and_money_totals(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $type = TicketType::factory()->for($event)->create(['quantity' => 10]);

        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_percent' => '5.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        Ticket::factory()->for($event)->for($type, 'ticketType')->for($order, 'order')->create(['status' => TicketStatus::Used, 'checked_in_at' => now()]);
        Ticket::factory()->for($event)->for($type, 'ticketType')->for($order, 'order')->create(['status' => TicketStatus::Valid]);
        Ticket::factory()->for($event)->for($type, 'ticketType')->for($order, 'order')->create(['status' => TicketStatus::Cancelled]);

        $response = $this->actingAs($owner)->get(route('events.tickets.overview', $event));

        $response->assertOk();
        $response->assertSee('2', escape: false); // tickets sold (valid + used, cancelled excluded)
        $response->assertSee('Tickets sold', escape: false);
        $response->assertSee('Tickets remaining', escape: false);
        $response->assertSee('Checked in', escape: false);
        $response->assertSee('K200.00', escape: false); // gross sales
        $response->assertSee('K10.00', escape: false); // EventHost fees
        $response->assertSee('K190.00', escape: false); // host revenue
    }

    public function test_tickets_remaining_ignores_unlimited_types(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        TicketType::factory()->for($event)->create(['quantity' => 5]);
        TicketType::factory()->for($event)->create(['quantity' => null]);

        $response = $this->actingAs($owner)->get(route('events.tickets.overview', $event));

        $response->assertOk();
        $response->assertViewHas('ticketsRemaining', 5);
    }

    public function test_a_non_owner_cannot_view_the_overview(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($intruder)
            ->get(route('events.tickets.overview', $event))
            ->assertForbidden();
    }

    public function test_a_non_ticketed_event_404s_on_the_overview(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('events.tickets.overview', $event))
            ->assertNotFound();
    }
}
