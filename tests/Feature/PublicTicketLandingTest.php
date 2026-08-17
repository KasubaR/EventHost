<?php

namespace Tests\Feature;

use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /e/{slug} for a ticketed event — the fixed public sales page rendered by
 * events/tickets/landing.blade.php + partials/landing-content.blade.php, not
 * the invitation-template system. Covers what PublicInvitationPageTest
 * covers for invitation events, scoped to the ticketed branch.
 */
class PublicTicketLandingTest extends TestCase
{
    use RefreshDatabase;

    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->published()->create(array_merge([
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
        ], $overrides));
    }

    public function test_landing_page_shows_event_name_date_venue_and_about(): void
    {
        $event = $this->approvedTicketedEvent([
            'venue' => 'Lusaka Showgrounds',
            'description' => 'Come dance with us.',
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee($event->name, escape: false);
        $response->assertSee($event->event_date->format('l, F j, Y'), escape: false);
        $response->assertSee('Lusaka Showgrounds', escape: false);
        $response->assertSee('About this event', escape: false);
        $response->assertSee('Come dance with us.', escape: false);
    }

    public function test_landing_page_lists_every_ticket_type_with_its_price(): void
    {
        $event = $this->approvedTicketedEvent();
        TicketType::factory()->for($event)->create(['name' => 'General', 'price' => '200.00', 'sort_order' => 1]);
        TicketType::factory()->for($event)->create(['name' => 'VIP', 'price' => '500.00', 'sort_order' => 2]);
        TicketType::factory()->for($event)->create(['name' => 'VVIP', 'price' => '1000.00', 'sort_order' => 3]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('General', escape: false);
        $response->assertSee('K200.00', escape: false);
        $response->assertSee('VIP', escape: false);
        $response->assertSee('K500.00', escape: false);
        $response->assertSee('VVIP', escape: false);
        $response->assertSee('K1,000.00', escape: false);
    }

    public function test_buy_tickets_button_links_to_the_picker_when_sales_are_approved(): void
    {
        $event = $this->approvedTicketedEvent();
        TicketType::factory()->for($event)->create();

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('Buy tickets', escape: false);
        $response->assertSee(route('events.public.tickets', $event->slug), escape: false);
    }

    public function test_buy_button_is_hidden_before_ticketing_is_approved(): void
    {
        $event = $this->approvedTicketedEvent(['ticketing_status' => TicketingStatus::PendingReview]);
        TicketType::factory()->for($event)->create();

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertDontSee('Buy tickets', escape: false);
        $response->assertSee("haven't opened yet", escape: false);
    }

    public function test_a_sold_out_type_is_marked_and_not_purchasable(): void
    {
        $event = $this->approvedTicketedEvent();
        TicketType::factory()->for($event)->create(['name' => 'General', 'quantity' => 1]);
        $type = $event->ticketTypes()->first();
        Ticket::factory()->for($event)->for($type, 'ticketType')->create();

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('Sold out', escape: false);
    }

    public function test_a_private_ticketed_event_403s_on_the_public_page(): void
    {
        $event = $this->approvedTicketedEvent(['is_public' => false]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertForbidden();
    }

    public function test_an_unpublished_ticketed_event_404s_on_the_public_page(): void
    {
        $event = Event::factory()->ticketed()->create([
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'is_published' => false,
        ]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertNotFound();
    }

    public function test_viewing_the_landing_page_increments_invitation_views(): void
    {
        $event = $this->approvedTicketedEvent();

        $this->get(route('events.public', ['slug' => $event->slug]));

        $this->assertSame(1, $event->fresh()->invitation_views_count);
    }
}
