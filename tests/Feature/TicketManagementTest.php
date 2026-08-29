<?php

namespace Tests\Feature;

use App\Enums\TicketingStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TicketOrderConfirmationNotification;
use App\Services\TicketPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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

    public function test_reissue_rotates_the_public_token_and_leaves_the_ticket_valid(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $order = TicketOrder::factory()->for($event)->paid()->create(['buyer_email' => 'buyer@example.com']);
        $ticket = Ticket::factory()->for($event)->for($order, 'order')->create(['status' => TicketStatus::Valid]);
        $oldToken = $ticket->public_token;

        $this->actingAs($owner)
            ->post(route('events.tickets.reissue', [$event, $ticket]))
            ->assertRedirect()
            ->assertSessionHas('status', 'ticket-reissued');

        $ticket->refresh();
        $this->assertNotSame($oldToken, $ticket->public_token);
        // The whole point of reissue over cancel: the seat and the buyer's
        // entitlement survive, only the credential changes.
        $this->assertSame(TicketStatus::Valid, $ticket->status);

        Notification::assertSentOnDemand(
            TicketOrderConfirmationNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'buyer@example.com'
        );
    }

    public function test_a_reissued_tickets_old_link_stops_resolving(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);
        $oldToken = $ticket->public_token;

        $this->actingAs($owner)->post(route('events.tickets.reissue', [$event, $ticket]));

        // A screenshot already in circulation points here.
        $this->get(route('tickets.show', ['token' => $oldToken]))->assertNotFound();
        $this->get(route('tickets.show', ['token' => $ticket->fresh()->public_token]))->assertOk();
    }

    public function test_reissue_drops_the_old_tokens_cached_qr_and_pdf(): void
    {
        Notification::fake();
        Storage::fake('local');

        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);
        $oldToken = $ticket->public_token;

        $oldPdfPath = app(TicketPdfService::class)->cachePathForToken($oldToken);
        $oldQrKey = Ticket::qrCacheKeyForToken($oldToken);

        Storage::disk('local')->put($oldPdfPath, 'cached-pdf');
        Cache::put($oldQrKey, '<svg></svg>', now()->addWeek());

        $this->actingAs($owner)->post(route('events.tickets.reissue', [$event, $ticket]));

        Storage::disk('local')->assertMissing($oldPdfPath);
        $this->assertNull(Cache::get($oldQrKey));
    }

    public function test_reissue_is_refused_for_a_ticket_that_is_not_valid(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Used]);
        $oldToken = $ticket->public_token;

        $this->actingAs($owner)
            ->post(route('events.tickets.reissue', [$event, $ticket]))
            ->assertSessionHasErrors('ticket');

        $this->assertSame($oldToken, $ticket->fresh()->public_token);
    }

    public function test_reissue_is_refused_for_someone_elses_event(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);
        $oldToken = $ticket->public_token;

        $this->actingAs($stranger)
            ->post(route('events.tickets.reissue', [$event, $ticket]))
            ->assertForbidden();

        $this->assertSame($oldToken, $ticket->fresh()->public_token);
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
        $event = $this->ticketedEvent($owner, ['event_date' => now()->toDateString(), 'ticketing_status' => TicketingStatus::Approved]);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);

        $this->actingAs($owner)
            ->post(route('events.tickets.confirm-checkin', [$event, $ticket]))
            ->assertRedirect();

        $ticket->refresh();
        $this->assertSame(TicketStatus::Used, $ticket->status);
        $this->assertNotNull($ticket->checked_in_at);
        $this->assertSame($owner->id, $ticket->checked_in_by);
    }

    /**
     * The gate is ticketing approval, not subscription tier — see
     * Event::ownerHasPremiumEventTools(). Redirects to the ticket-types
     * (Settings) page, not billing — there's nothing to buy.
     */
    public function test_unapproved_ticketed_event_is_redirected_from_table_checkin(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner, ['event_date' => now()->toDateString()]);
        $ticket = Ticket::factory()->for($event)->create(['status' => TicketStatus::Valid]);

        $this->actingAs($owner)
            ->post(route('events.tickets.confirm-checkin', [$event, $ticket]))
            ->assertRedirect(route('events.ticket-types.index', $event));

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

    public function test_owner_can_export_tickets_csv_with_contact_fields_and_ticket_numbers(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner, ['name' => 'Sunset Concert']);
        $type = TicketType::factory()->for($event)->create(['name' => 'VIP']);
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'order_reference' => 'ORD-TEST-001',
            'buyer_name' => 'Buyer Name',
            'buyer_email' => 'buyer@example.com',
            'buyer_phone' => '0961234567',
        ]);
        Ticket::factory()->for($event)->for($type, 'ticketType')->for($order, 'order')->create([
            'attendee_name' => 'John Banda',
            'attendee_email' => 'john@example.com',
            'attendee_phone' => '0977654321',
            'status' => TicketStatus::Valid,
        ]);
        Ticket::factory()->for($event)->for($type, 'ticketType')->for($order, 'order')->create([
            'attendee_name' => 'Mary Phiri',
            'attendee_email' => 'mary@example.com',
            'attendee_phone' => '0955555555',
            'status' => TicketStatus::Valid,
        ]);

        $response = $this->actingAs($owner)->get(route('events.tickets.export', $event));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        $this->assertSame(
            ['Ticket Number', 'Name', 'Email', 'Phone', 'Ticket Type', 'Order Reference', 'Status', 'Checked In', 'Checked In By'],
            $rows[0]
        );
        // Checked In By is blank for an unscanned ticket, the same way Checked
        // In reads "No" — see the staff-link tests for the populated case.
        $this->assertSame(
            ['EH-001', 'John Banda', 'john@example.com', '0977654321', 'VIP', 'ORD-TEST-001', 'Valid', 'No', ''],
            $rows[1]
        );
        $this->assertSame(
            ['EH-002', 'Mary Phiri', 'mary@example.com', '0955555555', 'VIP', 'ORD-TEST-001', 'Valid', 'No', ''],
            $rows[2]
        );
    }

    public function test_export_includes_cancelled_tickets_with_blank_checked_in(): void
    {
        $owner = User::factory()->create();
        $event = $this->ticketedEvent($owner);
        $order = TicketOrder::factory()->for($event)->paid()->create(['order_reference' => 'ORD-CXL']);
        Ticket::factory()->for($event)->for($order, 'order')->create([
            'attendee_name' => 'Cancelled Guest',
            'attendee_email' => 'cxl@example.com',
            'attendee_phone' => null,
            'status' => TicketStatus::Cancelled,
        ]);

        $rows = array_map(
            'str_getcsv',
            array_filter(explode("\n", trim(
                $this->actingAs($owner)->get(route('events.tickets.export', $event))->streamedContent()
            )))
        );

        $this->assertSame('EH-001', $rows[1][0]);
        $this->assertSame('Cancelled Guest', $rows[1][1]);
        $this->assertSame('cxl@example.com', $rows[1][2]);
        $this->assertSame('Cancelled', $rows[1][6]);
        $this->assertSame('', $rows[1][7]);
    }

    public function test_a_non_owner_cannot_export_tickets(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = $this->ticketedEvent($owner);

        $this->actingAs($intruder)
            ->get(route('events.tickets.export', $event))
            ->assertForbidden();
    }

    public function test_export_404s_for_an_invitation_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('events.tickets.export', $event))
            ->assertNotFound();
    }
}
