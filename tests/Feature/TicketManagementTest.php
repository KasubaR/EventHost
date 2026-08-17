<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TicketOrderConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Ticketing section's Tickets tab (Phase 16) — see
 * EventTicketManagementController and events/tickets/manage.blade.php.
 */
class TicketManagementTest extends TestCase
{
    use RefreshDatabase;

    private function ticketedEvent(User $owner, array $overrides = []): Event
    {
        return Event::factory()->for($owner)->ticketed()->create($overrides);
    }

    public function test_the_tickets_table_lists_type_buyer_status_and_checkin(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $type = TicketType::factory()->for($event)->create(['name' => 'VIP']);
        $order = TicketOrder::factory()->for($event)->paid()->create(['buyer_name' => 'John Banda']);
        Ticket::factory()->for($event)->for($type, 'ticketType')->for($order, 'order')->create([
            'attendee_name' => 'John Banda',
            'status' => TicketStatus::Used,
            'checked_in_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get(route('events.tickets.index', $event));

        $response->assertOk();
        $response->assertSee('VIP', escape: false);
        $response->assertSee('John Banda', escape: false);
        $response->assertSee('Used', escape: false);
        $response->assertSee('EH-001', escape: false);
    }

    public function test_a_cancelled_tickets_checkin_column_shows_a_dash(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        Ticket::factory()->for($event)->create(['status' => TicketStatus::Cancelled]);

        $this->actingAs($owner)
            ->get(route('events.tickets.index', $event))
            ->assertOk()
            ->assertSee('—', escape: false);
    }

    public function test_resend_sends_the_order_confirmation_notification_to_the_buyer(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $order = TicketOrder::factory()->for($event)->paid()->create(['buyer_email' => 'buyer@example.com']);
        $ticket = Ticket::factory()->for($event)->for($order, 'order')->create();

        $this->actingAs($owner)
            ->post(route('events.tickets.resend', [$event, $ticket]))
            ->assertRedirect();

        Notification::assertSentOnDemand(
            TicketOrderConfirmationNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'buyer@example.com'
        );
    }

    public function test_cancel_flips_a_valid_ticket_to_cancelled(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);

        $this->actingAs($owner)
            ->post(route('events.tickets.cancel', [$event, $ticket]))
            ->assertRedirect();

        $this->assertSame(TicketStatus::Cancelled, $ticket->fresh()->status);
    }

    public function test_cancel_is_refused_for_an_already_used_ticket(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Used]);

        $this->actingAs($owner)
            ->post(route('events.tickets.cancel', [$event, $ticket]))
            ->assertSessionHasErrors('ticket');

        $this->assertSame(TicketStatus::Used, $ticket->fresh()->status);
    }

    public function test_confirm_checkin_from_the_management_table_flips_valid_to_used(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->ticketedEvent($owner, ['event_date' => now()->toDateString()]);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);

        $this->actingAs($owner)
            ->post(route('events.tickets.confirm-checkin', [$event, $ticket]))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Used, $ticket->status);
        $this->assertNotNull($ticket->checked_in_at);
        $this->assertSame($owner->id, $ticket->checked_in_by);
    }

    public function test_base_tier_owner_is_redirected_to_billing_from_table_checkin(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner, ['event_date' => now()->toDateString()]);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);

        $this->actingAs($owner)
            ->post(route('events.tickets.confirm-checkin', [$event, $ticket]))
            ->assertRedirect(route('billing.show'));

        $this->assertSame(TicketStatus::Valid, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->checked_in_at);
    }

    public function test_checkin_action_is_hidden_for_a_base_tier_owner(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);

        $this->actingAs($owner)
            ->get(route('events.tickets.index', $event))
            ->assertOk()
            ->assertDontSee(route('events.tickets.confirm-checkin', [$event, $event->tickets()->first()]), escape: false);
    }

    public function test_a_non_owner_cannot_manage_tickets(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create();

        $this->actingAs($intruder)->get(route('events.tickets.index', $event))->assertForbidden();
        $this->actingAs($intruder)->post(route('events.tickets.cancel', [$event, $ticket]))->assertForbidden();
    }

    public function test_a_ticket_from_a_different_event_404s_on_row_actions(): void
    {
        $owner = User::factory()->create();
        $eventA = $this->ticketedEvent($owner);
        $eventB = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($eventB)->create();

        $this->actingAs($owner)
            ->post(route('events.tickets.cancel', ['event' => $eventA, 'ticket' => $ticket]))
            ->assertNotFound();
    }
}
