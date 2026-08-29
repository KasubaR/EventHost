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

    /**
     * Starting now, on the venue's clock, puts the event squarely inside the
     * check-in window whatever wall-clock time the suite runs at. The factory's
     * random event_time made this flaky once the window gained a 12-hour tail.
     */
    private function ticketedEventOnToday(User $owner, array $overrides = []): Event
    {
        $localNow = now()->timezone(config('events.timezone'));

        return Event::factory()->for($owner)->ticketed()->approved()->create(array_merge([
            'event_date' => $localNow->toDateString(),
            'event_time' => $localNow->format('H:i:s'),
        ], $overrides));
    }

    /**
     * The gate is ticketing approval, not subscription tier — a Pro owner is
     * blocked here exactly as a base-tier one would be, proving tier is
     * irrelevant once an event is ticketed. Redirects to the ticket-types
     * (Settings) page, not billing — there's nothing to buy, only approval
     * to wait for.
     */
    public function test_unapproved_ticketed_event_is_redirected_from_the_scanner(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($owner)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertRedirect(route('events.ticket-types.index', $event))
            ->assertSessionHas('status', 'checkin-requires-approval');
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

    /**
     * The returned checked_in_at must stay the ORIGINAL arrival, not be
     * refreshed to now() — checkin-scanner.js renders it in the re-scan
     * warning ("already checked in at 7:42 PM (5 minutes ago)"), which is how
     * door staff tell a guest walking back in from a second person holding a
     * copy of the same QR. Bumping it would make every re-scan read "moments
     * ago" and hide exactly the case the warning exists to catch.
     */
    public function test_confirming_an_already_used_ticket_is_idempotent_and_not_an_error(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);
        $firstCheckIn = now()->subMinutes(5);
        $ticket = Ticket::factory()->for($event)->create([
            'status' => TicketStatus::Used,
            'checked_in_at' => $firstCheckIn,
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => true]);
        $response->assertJsonPath('ticket.checked_in_at', $firstCheckIn->toIso8601String());

        $this->assertSame(
            $firstCheckIn->toIso8601String(),
            $ticket->fresh()->checked_in_at->toIso8601String(),
        );
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

    /**
     * "QR invalid" — a token that doesn't belong to any ticket at all
     * (garbage, mistyped, or a photo of the wrong QR entirely), distinct
     * from test_a_token_from_a_different_event_is_rejected below (a *real*
     * token, just for another event). Same 404 code path, but this is the
     * more common real-world scanner miss and had no dedicated test.
     */
    public function test_an_unknown_qr_token_is_rejected_with_a_clear_message(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner);

        $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => 'totally-made-up-token']))
            ->assertNotFound()
            ->assertJsonPath('message', 'No matching ticket for this event.');
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

    public function test_check_in_is_refused_before_the_window_opens(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEventOnToday($owner, ['event_date' => now()->addDays(3)->toDateString()]);
        $ticket = Ticket::factory()->for($event)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('events.tickets.checkin.confirm-token', ['event' => $event, 'token' => $ticket->public_token]));

        $response->assertForbidden();
        $this->assertStringContainsString('Check-in opens', (string) $response->json('message'));

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
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
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
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
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
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
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

    public function test_scanner_page_hides_the_camera_outside_the_window(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create([
            'event_date' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($owner)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertOk()
            ->assertSee('Check-in opens', escape: false)
            ->assertDontSee('ckinVideo', escape: false);
    }

    public function test_scanner_page_shows_the_camera_inside_the_window(): void
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
