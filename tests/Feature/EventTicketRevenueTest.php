<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\TicketPayoutService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 23 — host-side read-only Revenue/Payouts tabs. Payouts are admin-
 * recorded only; there is no write action on either host route.
 */
class EventTicketRevenueTest extends TestCase
{
    use RefreshDatabase;

    private function paidTicketedEvent(User $owner): Event
    {
        return Event::factory()->ticketed()->for($owner)->create([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
    }

    public function test_owner_can_view_revenue_and_payouts_pages(): void
    {
        $owner = User::factory()->create();
        $event = $this->paidTicketedEvent($owner);
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $this->actingAs($owner)
            ->get(route('events.tickets.revenue', $event))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('events.tickets.payouts', $event))
            ->assertOk();
    }

    public function test_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->paidTicketedEvent($owner);

        $this->actingAs($stranger)
            ->get(route('events.tickets.revenue', $event))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get(route('events.tickets.payouts', $event))
            ->assertForbidden();
    }

    public function test_a_non_ticketed_event_404s_both_pages(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(); // default kind is Invitation

        $this->actingAs($owner)
            ->get(route('events.tickets.revenue', $event))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('events.tickets.payouts', $event))
            ->assertNotFound();
    }

    public function test_an_admin_recorded_payout_shows_up_on_the_hosts_payouts_page_and_reduces_pending(): void
    {
        $owner = User::factory()->create();
        $event = $this->paidTicketedEvent($owner);
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $admin = Admin::factory()->create();
        app(TicketPayoutService::class)->recordPayout($event->fresh(), $admin, 90.00, 'First payout', Carbon::today());

        $payoutsPage = $this->actingAs($owner)->get(route('events.tickets.payouts', $event));
        $payoutsPage->assertOk();
        $payoutsPage->assertSee('First payout');
        $payoutsPage->assertSee('K100.00'); // pending payout, 190 - 90

        $overview = $this->actingAs($owner)->get(route('events.tickets.overview', $event));
        $overview->assertOk();
        $overview->assertSee('K100.00'); // pending payout card
        $overview->assertSee('K190.00'); // host revenue card stays lifetime, unaffected
    }
}
