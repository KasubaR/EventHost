<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Exceptions\TicketPayoutExceedsBalanceException;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketOrder;
use App\Models\TicketPayout;
use App\Models\TicketRevenueEntry;
use App\Models\TicketType;
use App\Services\LencoService;
use App\Services\TicketOrderFulfillmentService;
use App\Services\TicketPayoutService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class TicketRevenueLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    public function test_completing_an_order_writes_a_sale_entry_matching_the_orders_own_amounts(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 10]);

        $this->post(route('events.public.tickets.hold', $event->slug), ['quantities' => [$type->id => 2]]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ledger_1',
            'status' => 'successful',
            'amount' => 400.00,
            'currency' => 'ZMW',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.tickets.checkout.store', $event->slug), [
            'name' => 'Ledger Buyer',
            'email' => 'ledger@example.com',
            'phone' => '0961234567',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertOk();

        $order = TicketOrder::query()->where('event_id', $event->id)->firstOrFail();

        $entry = TicketRevenueEntry::query()->where('ticket_order_id', $order->id)->firstOrFail();

        $this->assertSame(TicketRevenueEntry::TYPE_SALE, $entry->type);
        $this->assertSame((string) $order->face_value, (string) $entry->gross_amount);
        $this->assertSame((string) $order->commission_amount, (string) $entry->platform_fee);
        $this->assertSame((string) $order->buyer_fee, (string) $entry->buyer_fee);
        $this->assertSame((string) $order->host_amount, (string) $entry->host_amount);
        $this->assertSame((string) $order->buyer_total, (string) $entry->buyer_total);
        $this->assertSame((string) $order->host_amount, (string) $entry->balance_after);
    }

    public function test_balance_after_accumulates_across_multiple_sales_for_the_same_event(): void
    {
        Notification::fake();

        $event = $this->approvedTicketedEvent();
        $typeA = TicketType::factory()->for($event)->create(['price' => '100.00', 'quantity' => 10]);
        $typeB = TicketType::factory()->for($event)->create(['price' => '100.00', 'quantity' => 10]);

        $orderA = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '100.00',
            'commission_amount' => '5.00',
            'host_amount' => '95.00',
        ]);
        $orderA->items()->create([
            'ticket_type_id' => $typeA->id,
            'ticket_type_name' => $typeA->name,
            'unit_price' => '100.00',
            'quantity' => 1,
            'subtotal' => '100.00',
        ]);

        $orderB = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '100.00',
            'commission_amount' => '5.00',
            'host_amount' => '95.00',
        ]);
        $orderB->items()->create([
            'ticket_type_id' => $typeB->id,
            'ticket_type_name' => $typeB->name,
            'unit_price' => '100.00',
            'quantity' => 1,
            'subtotal' => '100.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $entryA = $ledger->recordSale($orderA);
        $entryB = $ledger->recordSale($orderB);

        $this->assertSame('95.00', (string) $entryA->balance_after);
        $this->assertSame('190.00', (string) $entryB->balance_after);
        $this->assertSame(190.0, $ledger->balanceFor($event->fresh()));
    }

    public function test_a_second_event_sale_does_not_pollute_the_first_events_balance(): void
    {
        $eventA = $this->approvedTicketedEvent();
        $eventB = $this->approvedTicketedEvent();

        $typeA = TicketType::factory()->for($eventA)->create(['price' => '100.00']);
        $typeB = TicketType::factory()->for($eventB)->create(['price' => '500.00']);

        $orderA = TicketOrder::factory()->for($eventA)->paid()->create([
            'face_value' => '100.00', 'commission_amount' => '5.00', 'host_amount' => '95.00',
        ]);
        $orderA->items()->create([
            'ticket_type_id' => $typeA->id, 'ticket_type_name' => $typeA->name,
            'unit_price' => '100.00', 'quantity' => 1, 'subtotal' => '100.00',
        ]);

        $orderB = TicketOrder::factory()->for($eventB)->paid()->create([
            'face_value' => '500.00', 'commission_amount' => '25.00', 'host_amount' => '475.00',
        ]);
        $orderB->items()->create([
            'ticket_type_id' => $typeB->id, 'ticket_type_name' => $typeB->name,
            'unit_price' => '500.00', 'quantity' => 1, 'subtotal' => '500.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $ledger->recordSale($orderA);
        $ledger->recordSale($orderB);

        $this->assertSame(95.0, $ledger->balanceFor($eventA->fresh()));
        $this->assertSame(475.0, $ledger->balanceFor($eventB->fresh()));
    }

    public function test_recording_the_same_order_twice_does_not_double_post(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00']);

        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        $order->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => $type->name,
            'unit_price' => '200.00', 'quantity' => 1, 'subtotal' => '200.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $first = $ledger->recordSale($order);
        $second = $ledger->recordSale($order);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, TicketRevenueEntry::query()->where('ticket_order_id', $order->id)->count());
        $this->assertSame(190.0, $ledger->balanceFor($event->fresh()));
    }

    public function test_complete_is_idempotent_and_still_only_posts_one_ledger_entry(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00', 'quantity' => 5]);

        $order = TicketOrder::factory()->for($event)->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        $order->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => $type->name,
            'unit_price' => '200.00', 'quantity' => 1, 'subtotal' => '200.00',
        ]);
        $order->payment()->create([
            'provider' => 'mtn', 'payment_method' => 'mobile_money',
            'amount' => '200.00', 'currency' => 'ZMW', 'status' => 'completed',
            'payment_reference' => $order->order_reference,
        ]);

        $fulfillment = app(TicketOrderFulfillmentService::class);
        $fulfillment->complete($order);
        $fulfillment->complete($order->fresh());

        $this->assertSame(1, TicketRevenueEntry::query()->where('ticket_order_id', $order->id)->count());
    }

    public function test_deleting_an_order_does_not_wipe_its_sale_entry(): void
    {
        $event = $this->approvedTicketedEvent();
        $type = TicketType::factory()->for($event)->create(['price' => '200.00']);

        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        $order->items()->create([
            'ticket_type_id' => $type->id, 'ticket_type_name' => $type->name,
            'unit_price' => '200.00', 'quantity' => 1, 'subtotal' => '200.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $entry = $ledger->recordSale($order);
        $this->assertNotNull($entry);

        $entryId = $entry->id;
        $eventId = $event->id;
        $order->delete();

        $this->assertDatabaseHas('ticket_revenue_entries', [
            'id' => $entryId,
            'event_id' => $eventId,
            'ticket_order_id' => null,
            'type' => TicketRevenueEntry::TYPE_SALE,
            'host_amount' => '190.00',
        ]);
        $this->assertSame(190.0, $ledger->balanceFor($event->fresh()));
    }

    public function test_duplicate_sale_insert_violates_the_unique_constraint(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create();

        TicketRevenueEntry::factory()->create([
            'event_id' => $event->id,
            'ticket_order_id' => $order->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        TicketRevenueEntry::factory()->create([
            'event_id' => $event->id,
            'ticket_order_id' => $order->id,
        ]);
    }

    public function test_summary_for_returns_gross_fees_and_host_revenue(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_percent' => '5.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $ledger->recordSale($order);

        $summary = $ledger->summaryFor($event->fresh());
        $this->assertSame(200.0, $summary['gross_amount']);
        $this->assertSame(10.0, $summary['platform_fee']);
        $this->assertSame(190.0, $summary['host_amount']);
    }

    public function test_recording_a_payout_writes_a_negative_entry_and_updates_the_balance(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $ledger->recordSale($order);

        $admin = Admin::factory()->create();
        $payout = app(TicketPayoutService::class)->recordPayout($event->fresh(), $admin, 100.00, 'First installment', Carbon::today());

        $this->assertInstanceOf(TicketPayout::class, $payout);
        $this->assertSame('100.00', (string) $payout->amount);
        $this->assertSame($admin->id, $payout->paid_by);

        $entry = TicketRevenueEntry::query()->where('id', $payout->ticket_revenue_entry_id)->firstOrFail();
        $this->assertSame(TicketRevenueEntry::TYPE_PAYOUT, $entry->type);
        $this->assertSame('-100.00', (string) $entry->host_amount);
        $this->assertSame('90.00', (string) $entry->balance_after);

        $this->assertSame(90.0, $ledger->balanceFor($event->fresh()));
    }

    public function test_a_payout_cannot_exceed_the_pending_balance(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $admin = Admin::factory()->create();

        $this->expectException(TicketPayoutExceedsBalanceException::class);
        app(TicketPayoutService::class)->recordPayout($event->fresh(), $admin, 190.01, null, Carbon::today());
    }

    public function test_a_payout_of_zero_or_less_is_rejected(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);
        app(TicketRevenueLedgerService::class)->recordSale($order);

        $admin = Admin::factory()->create();

        $this->expectException(TicketPayoutExceedsBalanceException::class);
        app(TicketPayoutService::class)->recordPayout($event->fresh(), $admin, 0.0, null, Carbon::today());
    }

    public function test_summary_for_is_unaffected_by_a_payout_while_balance_for_drops(): void
    {
        $event = $this->approvedTicketedEvent();
        $order = TicketOrder::factory()->for($event)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]);

        $ledger = app(TicketRevenueLedgerService::class);
        $ledger->recordSale($order);

        $admin = Admin::factory()->create();
        app(TicketPayoutService::class)->recordPayout($event->fresh(), $admin, 90.00, null, Carbon::today());

        $summary = $ledger->summaryFor($event->fresh());
        $this->assertSame(200.0, $summary['gross_amount']);
        $this->assertSame(10.0, $summary['platform_fee']);
        $this->assertSame(190.0, $summary['host_amount']);

        $this->assertSame(100.0, $ledger->balanceFor($event->fresh()));
    }
}
