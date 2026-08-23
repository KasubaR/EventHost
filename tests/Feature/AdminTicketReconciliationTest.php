<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Enums\TicketOrderStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketPayment;
use App\Models\TicketRevenueEntry;
use App\Models\User;
use App\Services\LencoService;
use App\Services\TicketRevenueLedgerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 24 — reconciliation dashboard (8 health checks + search/trace) and
 * the one mutation it adds (re-verify a stuck payment with Lenco). See
 * C:\Users\Sunny\.claude\plans (Phase 24 plan) for the design.
 */
class AdminTicketReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function support(): Admin
    {
        $support = Admin::factory()->create();
        $support->assignRole('support');

        return $support;
    }

    private function ticketedEvent(): Event
    {
        return Event::factory()->ticketed()->for(User::factory())->create([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
    }

    /**
     * A fully clean, correctly-fulfilled paid order — payment completed,
     * one item, one ticket, one sale ledger entry matching the order exactly.
     * Every check should stay quiet against this baseline.
     */
    private function cleanPaidOrder(?Event $event = null): TicketOrder
    {
        $event ??= $this->ticketedEvent();

        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00',
            'commission_amount' => '10.00',
            'commission_percent' => '5.00',
            'buyer_fee' => '0.00',
            'buyer_total' => '200.00',
            'host_amount' => '190.00',
        ]);
        TicketPayment::factory()->for($order, 'order')->create([
            'status' => 'completed',
            'amount' => '200.00',
            'completed_at' => now(),
        ]);
        $item = TicketOrderItem::factory()->for($order, 'order')->create(['quantity' => 1]);
        Ticket::factory()->for($order, 'order')->for($item, 'orderItem')->for($event)->create();
        app(TicketRevenueLedgerService::class)->recordSale($order);

        return $order;
    }

    public function test_index_and_order_pages_are_forbidden_without_ticketing_view(): void
    {
        // No role assigned at all.
        $intruder = Admin::factory()->create();
        $order = $this->cleanPaidOrder();

        $this->actingAs($intruder, 'admin')->get(route('admin.ticketing.reconciliation.index'))->assertForbidden();
        $this->actingAs($intruder, 'admin')->get(route('admin.ticketing.reconciliation.order', $order))->assertForbidden();
    }

    public function test_support_can_view_but_reverify_is_forbidden(): void
    {
        $order = $this->cleanPaidOrder();

        $this->actingAs($this->support(), 'admin')
            ->get(route('admin.ticketing.reconciliation.index'))
            ->assertOk();

        $this->actingAs($this->support(), 'admin')
            ->get(route('admin.ticketing.reconciliation.order', $order))
            ->assertOk();

        $this->actingAs($this->support(), 'admin')
            ->post(route('admin.ticketing.reconciliation.reverify', $order))
            ->assertForbidden();
    }

    public function test_a_clean_order_trips_no_checks(): void
    {
        $this->cleanPaidOrder();

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        // Every check card should render the "OK" pill, none of the
        // "N issue(s)" pills. Checking the CSS class rather than the word
        // "issue" — several check descriptions legitimately say "issued".
        $response->assertDontSee('evt-pill--declined', false);
    }

    public function test_completed_payment_with_unpaid_order_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->create(['status' => TicketOrderStatus::Failed]);
        $payment = TicketPayment::factory()->for($order, 'order')->create(['status' => 'completed', 'completed_at' => now()]);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($payment->payment_reference);
        $response->assertSee('Payment completed, order not paid');
    }

    public function test_paid_order_without_completed_payment_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create();
        // No TicketPayment row at all.

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($order->order_reference);
        $response->assertSee('Order paid, no completed payment on record');
    }

    public function test_ticket_count_mismatch_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create();
        TicketOrderItem::factory()->for($order, 'order')->create(['quantity' => 3]);
        // Only one ticket issued for a 3-quantity item.
        Ticket::factory()->for($order, 'order')->for($event)->create();

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($order->order_reference);
        $response->assertSee('ticket-count mismatch');
    }

    public function test_paid_order_missing_sale_entry_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create();
        // Deliberately never call the ledger service.

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($order->order_reference);
        $response->assertSee('no sale ledger entry');
    }

    public function test_ledger_amount_drift_is_flagged(): void
    {
        $order = $this->cleanPaidOrder();

        // Simulate drift: someone edited the order's host_amount after the
        // ledger entry was already written verbatim.
        $order->forceFill(['host_amount' => '999.00'])->save();

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($order->order_reference);
        $response->assertSee('differs from its order');
    }

    public function test_negative_event_balance_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        // A payout-type entry with no matching sale — balance goes negative.
        TicketRevenueEntry::factory()->for($event)->create([
            'ticket_order_id' => null,
            'type' => 'payout',
            'host_amount' => '-50.00',
            'balance_after' => '-50.00',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($event->name);
        $response->assertSee('negative running balance');
    }

    public function test_stuck_in_flight_payment_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->processing()->create();
        $payment = TicketPayment::factory()->for($order, 'order')->create(['status' => 'processing']);
        $payment->forceFill(['created_at' => now()->subHours(3)])->save();

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($payment->payment_reference);
        $response->assertSee('stuck pending/processing');
    }

    public function test_settlement_mismatch_is_flagged(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->failed()->create();
        $payment = TicketPayment::factory()->for($order, 'order')->create([
            'status' => 'failed',
            'failure_reason' => 'Amount or currency mismatch with provider settlement',
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.index'));

        $response->assertOk();
        $response->assertSee($payment->payment_reference);
        $response->assertSee('Amount/currency mismatch');
    }

    public function test_search_resolves_by_order_reference_payment_reference_lenco_id_and_amount(): void
    {
        $order = $this->cleanPaidOrder();
        $order->payment->update(['lenco_transaction_id' => 'col_trace_test']);

        $admin = $this->admin();

        foreach ([
            $order->order_reference,
            $order->payment->payment_reference,
            'col_trace_test',
            '200',
        ] as $query) {
            $this->actingAs($admin, 'admin')
                ->get(route('admin.ticketing.reconciliation.index', ['q' => $query]))
                ->assertOk()
                ->assertSee($order->order_reference);
        }
    }

    public function test_search_resolves_by_buyer_email(): void
    {
        $order = $this->cleanPaidOrder();
        $order->update(['buyer_email' => 'findme@example.com']);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.ticketing.reconciliation.index', ['q' => 'findme']))
            ->assertOk()
            ->assertSee($order->order_reference);
    }

    public function test_trace_page_renders_the_full_chain(): void
    {
        $order = $this->cleanPaidOrder();

        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.ticketing.reconciliation.order', $order));

        $response->assertOk();
        $response->assertSee($order->order_reference);
        $response->assertSee($order->payment->payment_reference);
        $response->assertSee($order->tickets->first()->attendee_name);
        $response->assertSee('Sale');
    }

    public function test_reverify_updates_a_stuck_payment_via_lenco(): void
    {
        $event = $this->ticketedEvent();
        $order = TicketOrder::factory()->for($event)->processing()->create([
            'order_reference' => 'TKT-REVERIFY-1',
            'face_value' => '200.00',
            'buyer_total' => '200.00',
            'commission_amount' => '10.00',
            'host_amount' => '190.00',
        ]);
        TicketOrderItem::factory()->for($order, 'order')->create(['quantity' => 1]);
        $payment = TicketPayment::factory()->for($order, 'order')->create([
            'status' => 'processing',
            'amount' => '200.00',
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyByReference')->once()->with('TKT-REVERIFY-1')->andReturn([
            'transactionId' => 'col_reverify',
            'lencoStatus' => 'successful',
            'status' => 'completed',
            'amount' => 200.0,
            'currency' => 'ZMW',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.ticketing.reconciliation.reverify', $order))
            ->assertRedirect(route('admin.ticketing.reconciliation.order', $order))
            ->assertSessionHas('status', 'reverified');

        $payment->refresh();
        $order->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertSame(TicketOrderStatus::Paid, $order->status);
    }
}
