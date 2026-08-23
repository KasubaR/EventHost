<?php

namespace Tests\Feature;

use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 17 — the ticket-side twin of StaffScannerLinkTest (guests). Reuses
 * EventStaffLinkController for create/revoke (already generic) and
 * PublicTicketCheckInController for the no-login scan surface.
 */
class TicketStaffScannerLinkTest extends TestCase
{
    use RefreshDatabase;

    private function ticketedEventOnToday(User $owner, array $overrides = []): Event
    {
        return Event::factory()->for($owner)->ticketed()->create(array_merge([
            'event_date' => now()->toDateString(),
        ], $overrides));
    }

    public function test_owner_can_create_a_staff_scanner_link_for_a_ticketed_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();

        $this->actingAs($owner)
            ->post(route('events.checkin.links.store', $event), ['label' => 'Front gate'])
            ->assertRedirect(route('events.tickets.checkin.scan', $event));

        $link = EventStaffLink::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('Front gate', $link->label);
    }

    public function test_owner_can_revoke_a_ticket_staff_scanner_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $this->actingAs($owner)
            ->delete(route('events.checkin.links.destroy', ['event' => $event, 'link' => $link]))
            ->assertRedirect(route('events.tickets.checkin.scan', $event));

        $this->assertNull(EventStaffLink::find($link->id));
    }

    public function test_staff_link_scanner_url_points_at_the_ticket_route_for_a_ticketed_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $this->assertSame(
            route('tickets.checkin.public.scan', ['staffToken' => $link->token], absolute: true),
            $link->scannerUrl(),
        );
    }

    public function test_public_scan_page_is_active_for_a_valid_ticket_link(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $response = $this->get(route('tickets.checkin.public.scan', ['staffToken' => $link->token]));

        $response->assertOk();
        $response->assertDontSee("isn't active", false);
    }

    public function test_public_scan_page_is_inactive_for_a_revoked_ticket_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $link = EventStaffLink::factory()->for($event)->revoked()->create();

        $this->get(route('tickets.checkin.public.scan', ['staffToken' => $link->token]))
            ->assertSee("isn't active", false);
    }

    public function test_public_scan_page_is_inactive_for_an_unknown_token(): void
    {
        $this->get(route('tickets.checkin.public.scan', ['staffToken' => 'not-a-real-token']))
            ->assertSee("isn't active", false);
    }

    public function test_staff_link_confirms_ticket_check_in_by_token_without_authentication(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEventOnToday($owner, ['ticketing_status' => TicketingStatus::Approved]);
        $link = EventStaffLink::factory()->for($event)->create();
        $ticket = Ticket::factory()->for($event)->create();

        $response = $this->postJson(route('tickets.checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $ticket->public_token,
        ]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => false]);

        $ticket->refresh();
        $this->assertNotNull($ticket->checked_in_at);
        $this->assertNull($ticket->checked_in_by);
        $this->assertNotNull($link->fresh()->last_used_at);
    }

    public function test_staff_link_confirms_ticket_check_in_by_ticket_id(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEventOnToday($owner, ['ticketing_status' => TicketingStatus::Approved]);
        $link = EventStaffLink::factory()->for($event)->create();
        $ticket = Ticket::factory()->for($event)->create();

        $this->postJson(route('tickets.checkin.public.confirm-ticket', [
            'staffToken' => $link->token,
            'ticket' => $ticket->id,
        ]))->assertOk();

        $this->assertNotNull($ticket->fresh()->checked_in_at);
    }

    public function test_revoked_ticket_link_cannot_confirm_check_in(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $link = EventStaffLink::factory()->for($event)->revoked()->create();
        $ticket = Ticket::factory()->for($event)->create();

        $this->postJson(route('tickets.checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $ticket->public_token,
        ]))->assertForbidden();

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    /**
     * The gate is ticketing approval, not subscription tier — a Pro owner is
     * blocked here exactly as a base-tier one would be. ticketedEventOnToday()
     * defaults to Draft (unapproved).
     */
    public function test_ticket_link_for_an_unapproved_event_cannot_confirm_check_in(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $link = EventStaffLink::factory()->for($event)->create();
        $ticket = Ticket::factory()->for($event)->create();

        $this->postJson(route('tickets.checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $ticket->public_token,
        ]))->assertForbidden();
    }

    public function test_a_guest_events_staff_link_cannot_be_used_on_the_ticket_scan_route(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();
        $ticket = Ticket::factory()->create();

        $this->get(route('tickets.checkin.public.scan', ['staffToken' => $link->token]))
            ->assertOk()
            ->assertSee("isn't active", false);

        $this->getJson(route('tickets.checkin.public.lookup', ['staffToken' => $link->token]).'?q=Alice')
            ->assertNotFound();

        $this->postJson(route('tickets.checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $ticket->public_token,
        ]))->assertNotFound();
    }

    public function test_a_ticket_events_staff_link_cannot_be_used_on_the_guest_scan_route(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $link = EventStaffLink::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->get(route('checkin.public.scan', ['staffToken' => $link->token]))
            ->assertOk()
            ->assertSee("isn't active", false);

        $this->getJson(route('checkin.public.lookup', ['staffToken' => $link->token]).'?q=Alice')
            ->assertNotFound();

        $this->postJson(route('checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $guest->invitation_token,
        ]))->assertNotFound();

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_lookup_rejects_an_empty_query(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $link = EventStaffLink::factory()->for($event)->create();
        Ticket::factory()->for($event)->create(['attendee_name' => 'Alice Wonder']);

        $this->getJson(route('tickets.checkin.public.lookup', ['staffToken' => $link->token]).'?q=')
            ->assertOk()
            ->assertJsonCount(0, 'tickets');

        $this->getJson(route('tickets.checkin.public.lookup', ['staffToken' => $link->token]).'?q=A')
            ->assertOk()
            ->assertJsonCount(0, 'tickets');
    }

    public function test_lookup_works_via_ticket_staff_link(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $link = EventStaffLink::factory()->for($event)->create();
        Ticket::factory()->for($event)->create(['attendee_name' => 'Alice Wonder']);

        $response = $this->getJson(route('tickets.checkin.public.lookup', ['staffToken' => $link->token]).'?q=Alice');

        $response->assertOk();
        $response->assertJsonCount(1, 'tickets');
    }
}
