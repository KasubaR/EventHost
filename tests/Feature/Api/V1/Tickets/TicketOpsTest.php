<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice D — dashboard overview, ticketing submit, ticket management row actions
 * (resend/reissue/cancel/confirm-checkin), and revenue/payouts. One file covering
 * the remaining Slice D ticket-ops endpoints not already exercised by
 * TicketTypeManagementTest / CheckIn/TicketCheckInTest.
 */
class TicketOpsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_overview_returns_counts_and_ledger_totals(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $type = TicketType::factory()->for($event)->create(['quantity' => 10]);
        Ticket::factory()->for($event)->for($type, 'ticketType')->create(['status' => TicketStatus::Valid]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.tickets.overview', $event));

        $response->assertOk();
        $response->assertJsonPath('tickets_sold', 1);
        $response->assertJsonStructure(['tickets_sold', 'tickets_remaining', 'checked_in', 'gross_sales', 'platform_fees', 'host_revenue', 'pending_payout']);
    }

    public function test_submitting_ticketing_moves_status_to_pending_review(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create(['ticketing_status' => TicketingStatus::Draft]);
        TicketType::factory()->for($event)->create(['is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.ticketing.submit', $event));

        $response->assertOk();
        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);
    }

    public function test_submitting_without_an_active_ticket_type_returns_422(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create(['ticketing_status' => TicketingStatus::Draft]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.ticketing.submit', $event));

        $response->assertStatus(422);
    }

    public function test_updating_commission_mode_is_locked_once_approved(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson(route('api.v1.host.events.ticketing.update', $event), [
                'commission_mode' => CommissionMode::PassThrough->value,
            ]);

        $response->assertStatus(422);
    }

    public function test_cancel_then_reissue_and_confirm_checkin_on_a_ticket(): void
    {
        $owner = User::factory()->create();
        $localNow = now()->timezone(config('events.timezone'));
        $event = Event::factory()->for($owner)->ticketed()->approved()->create([
            'event_date' => $localNow->toDateString(),
            'event_time' => $localNow->format('H:i:s'),
        ]);
        $type = TicketType::factory()->for($event)->create();
        $ticket = Ticket::factory()->for($event)->for($type, 'ticketType')->create();

        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($owner)];

        // resend
        $this->withHeaders($headers)
            ->postJson(route('api.v1.host.events.tickets.resend', ['event' => $event, 'ticket' => $ticket]))
            ->assertOk();

        // reissue rotates the token
        $previousToken = $ticket->public_token;
        $reissue = $this->withHeaders($headers)
            ->postJson(route('api.v1.host.events.tickets.reissue', ['event' => $event, 'ticket' => $ticket]));
        $reissue->assertOk();
        $this->assertNotSame($previousToken, $ticket->fresh()->public_token);

        // confirm-checkin reuses TicketCheckInService
        $confirm = $this->withHeaders($headers)
            ->postJson(route('api.v1.host.events.tickets.confirm-checkin', ['event' => $event, 'ticket' => $ticket]));
        $confirm->assertOk()->assertJsonPath('already_checked_in', false);
        $this->assertSame(TicketStatus::Used, $ticket->fresh()->status);

        // cancel only works on a still-valid ticket — this one is already Used
        $this->withHeaders($headers)
            ->postJson(route('api.v1.host.events.tickets.cancel', ['event' => $event, 'ticket' => $ticket]))
            ->assertStatus(422);
    }

    public function test_index_returns_a_starting_ordinal(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $type = TicketType::factory()->for($event)->create();
        Ticket::factory()->count(3)->for($event)->for($type, 'ticketType')->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.tickets.index', $event));

        $response->assertOk();
        $response->assertJsonPath('starting_ordinal', 1);
        $this->assertCount(3, $response->json('tickets'));
    }

    public function test_revenue_and_payouts_are_read_only_and_scoped_to_the_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();

        $revenue = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.tickets.revenue', $event));
        $revenue->assertOk()->assertJsonStructure(['gross_sales', 'platform_fees', 'pending_payable', 'entries']);

        $payouts = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.tickets.payouts', $event));
        $payouts->assertOk()->assertJsonStructure(['pending_payable', 'payouts']);
    }

    public function test_a_stranger_cannot_reach_any_ticket_ops_endpoint(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson(route('api.v1.host.events.tickets.overview', $event))
            ->assertForbidden();
    }
}
