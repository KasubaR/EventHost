<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketCheckInTest extends TestCase
{
    use RefreshDatabase;

    private function ticketedEventOnToday(User $owner, array $overrides = []): Event
    {
        return Event::factory()->for($owner)->ticketed()->create(array_merge([
            'event_date' => now()->toDateString(),
        ], $overrides));
    }

    public function test_base_tier_owner_is_redirected_to_billing_from_scanner(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($owner)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertRedirect(route('billing.show'));
    }

    public function test_a_non_ticketed_event_404s_on_the_ticket_scanner(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertNotFound();
    }

    public function test_owner_can_confirm_check_in_by_token(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $type = TicketType::factory()->for($event)->create(['name' => 'VIP']);
        $ticket = Ticket::factory()->for($event)->for($type, 'ticketType')->create();

        $response = $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => false]);
        $response->assertJsonPath('ticket.attendee_name', $ticket->attendee_name);
        $response->assertJsonPath('ticket.ticket_type', 'VIP');

        $ticket->refresh();
        $this->assertNotNull($ticket->checked_in_at);
        $this->assertSame($owner->id, $ticket->checked_in_by);
        $this->assertSame(TicketStatus::Used, $ticket->status);
    }

    public function test_confirming_an_already_used_ticket_is_idempotent_and_not_an_error(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($event)->create([
            'status' => TicketStatus::Used,
            'checked_in_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => true]);
    }

    public function test_a_cancelled_ticket_is_refused(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Cancelled]);

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]))
            ->assertForbidden()
            ->assertJsonPath('message', 'This ticket has been cancelled.');

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_a_refunded_ticket_is_refused(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Refunded]);

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]))
            ->assertForbidden()
            ->assertJsonPath('message', 'This ticket has been cancelled.');

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_a_token_from_a_different_event_is_rejected(): void
    {
        $owner = User::factory()->pro()->create();
        $eventA = $this->ticketedEventOnToday($owner);
        $eventB = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($eventB)->create();

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $eventA, 'token' => $ticket->public_token]))
            ->assertNotFound();

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_another_hosts_ticket_scanner_cannot_be_used(): void
    {
        $owner = User::factory()->pro()->create();
        $intruder = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($event)->create();

        $this->actingAs($intruder)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]))
            ->assertForbidden();

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_ticket_holder_cannot_self_check_in_without_being_authenticated(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($event)->create();

        $this->post(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]))
            ->assertRedirect(route('login'));

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_check_in_is_refused_before_the_event_date(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner, ['event_date' => now()->addDay()->toDateString()]);
        $ticket = Ticket::factory()->for($event)->create();

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]))
            ->assertForbidden()
            ->assertJsonPath('message', 'Check-in is only available on the event date.');

        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_confirm_by_ticket_id_from_manual_lookup(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $ticket = Ticket::factory()->for($event)->create();

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-ticket', ['event' => $event, 'ticket' => $ticket]))
            ->assertOk()
            ->assertJson(['already_checked_in' => false]);

        $this->assertNotNull($ticket->fresh()->checked_in_at);
    }

    public function test_lookup_returns_matching_tickets_by_attendee_name(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        Ticket::factory()->for($event)->create(['attendee_name' => 'Alice Wonder']);
        Ticket::factory()->for($event)->create(['attendee_name' => 'Bob Builder']);

        $response = $this->actingAs($owner)
            ->getJson(route('events.tickets.checkin.lookup', $event).'?q=Alice');

        $response->assertOk();
        $response->assertJsonCount(1, 'tickets');
        $response->assertJsonPath('tickets.0.name', 'Alice Wonder');
    }

    public function test_lookup_treats_like_wildcards_as_literals(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        Ticket::factory()->for($event)->create(['attendee_name' => 'Alice Wonder']);
        Ticket::factory()->for($event)->create(['attendee_name' => 'Bob Builder']);

        $this->actingAs($owner)
            ->getJson(route('events.tickets.checkin.lookup', $event).'?q=%')
            ->assertOk()
            ->assertJsonCount(0, 'tickets');
    }

    public function test_lookup_rejects_an_empty_or_one_character_query(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        Ticket::factory()->for($event)->create(['attendee_name' => 'Alice Wonder']);

        $this->actingAs($owner)
            ->getJson(route('events.tickets.checkin.lookup', $event).'?q=')
            ->assertOk()
            ->assertJsonCount(0, 'tickets');

        $this->actingAs($owner)
            ->getJson(route('events.tickets.checkin.lookup', $event).'?q=A')
            ->assertOk()
            ->assertJsonCount(0, 'tickets');
    }

    public function test_scanner_page_hides_the_camera_when_it_is_not_the_event_date(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'event_date' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($owner)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertOk()
            ->assertSee('Check-in is only available on the event date', escape: false)
            ->assertDontSee('ckinVideo', escape: false);
    }

    public function test_scanner_page_shows_the_camera_on_the_event_date(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);

        $this->actingAs($owner)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertOk()
            ->assertSee('ckinVideo', escape: false)
            ->assertSee('Scan again', escape: false);
    }
}
