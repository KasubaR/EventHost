<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketRevenueEntry;
use App\Models\User;
use App\Services\TicketRevenueLedgerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 23 — platform-wide ticket revenue dashboard and the per-event
 * payout-recording flow. See plans/ticketing.md and plans in
 * C:\Users\Sunny\.claude\plans (Phase 23 plan) for the design.
 */
class AdminTicketRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function paidEvent(array $overrides = []): Event
    {
        $owner = User::factory()->create();

        return Event::factory()->ticketed()->for($owner)->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    public function test_support_can_view_the_revenue_dashboard_but_cannot_record_a_payout(): void
    {
        $event = $this->paidEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $support = Admin::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support, 'admin')
            ->get(route('admin.ticketing.revenue.index'))
            ->assertOk();

        $this->actingAs($support, 'admin')
            ->get(route('admin.ticketing.revenue.show', $event))
            ->assertOk();

        $this->actingAs($support, 'admin')
            ->post(route('admin.ticketing.revenue.payouts.store', $event), [
                'amount' => '50.00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_record_a_payout_and_it_appears_on_both_pages(): void
    {
        $event = $this->paidEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.revenue.payouts.store', $event), [
                'amount' => '100.00',
                'paid_on' => now()->toDateString(),
                'note' => 'Bank transfer',
            ])
            ->assertRedirect(route('admin.ticketing.revenue.show', $event));

        $this->assertDatabaseHas('ticket_payouts', [
            'event_id' => $event->id,
            'amount' => '100.00',
            'note' => 'Bank transfer',
            'paid_by' => $admin->id,
        ]);

        $show = $this->actingAs($admin, 'admin')->get(route('admin.ticketing.revenue.show', $event));
        $show->assertOk();
        $show->assertSee('Bank transfer');

        $index = $this->actingAs($admin, 'admin')->get(route('admin.ticketing.revenue.index'));
        $index->assertOk();
        $index->assertSee('K100.00'); // completed payouts card
    }

    public function test_a_payout_larger_than_the_balance_is_rejected(): void
    {
        $event = $this->paidEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.revenue.payouts.store', $event), [
                'amount' => '999.00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertRedirect(route('admin.ticketing.revenue.show', $event))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('ticket_payouts', 0);
    }

    public function test_todays_sales_only_counts_entries_created_today(): void
    {
        $event = $this->paidEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '300.00', 'commission_amount' => '15.00', 'host_amount' => '285.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $this->travelTo(now()->addDay());

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'admin')->get(route('admin.ticketing.revenue.index'));
        $response->assertOk();
        $response->assertSee('K0.00'); // today's ticket sales, nothing sold "today"
    }

    /**
     * ticket_revenue_entries.event_id is nullable (nullOnDelete) — a deleted
     * event's sale rows survive for audit history. The per-event table must
     * not crash on one; it simply can't be shown as a row (nothing to link
     * to), so it's excluded.
     */
    public function test_an_orphaned_ledger_row_with_no_event_does_not_break_the_dashboard(): void
    {
        $event = $this->paidEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        TicketRevenueEntry::factory()->create([
            'event_id' => null,
            'ticket_order_id' => null,
            'host_amount' => '475.00',
            'balance_after' => '475.00',
        ]);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ticketing.revenue.index'))
            ->assertOk()
            ->assertSee($event->name);
    }

    public function test_the_platform_breakdown_sums_correctly_across_multiple_events(): void
    {
        $eventA = $this->paidEvent();
        $eventB = $this->paidEvent();

        $orderA = TicketOrder::factory()->for($eventA)->paid()->create([
            'face_value' => '100.00', 'commission_amount' => '5.00', 'host_amount' => '95.00',
        ]);
        $orderB = TicketOrder::factory()->for($eventB)->paid()->create([
            'face_value' => '400.00', 'commission_amount' => '20.00', 'host_amount' => '380.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $ledger->recordSale($orderA);
        $ledger->recordSale($orderB);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin, 'admin')->get(route('admin.ticketing.revenue.index'));
        $response->assertOk();
        $response->assertSee('K500.00'); // gross sales, 100 + 400
        $response->assertSee('K25.00');  // platform revenue, 5 + 20
    }
}
