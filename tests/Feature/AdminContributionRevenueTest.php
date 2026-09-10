<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 of plans/contributions.md — platform-wide contribution revenue
 * dashboard and the per-event payout-recording flow. Twin of
 * AdminTicketRevenueTest.
 */
class AdminContributionRevenueTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contributingEvent(array $overrides = []): Event
    {
        $owner = User::factory()->create();

        return Event::factory()->for($owner)->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ], $overrides));
    }

    private function completedPayment(Event $event, string $amount = '60.00'): ContributionPayment
    {
        $contribution = EventContribution::factory()->for($event)->create([
            'target_amount' => '100.00',
            'amount_paid' => $amount,
        ]);

        return ContributionPayment::factory()->for($contribution, 'contribution')->create([
            'amount' => $amount,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function test_support_cannot_view_or_manage_contribution_revenue(): void
    {
        $event = $this->contributingEvent();
        $this->completedPayment($event);

        $support = $this->support();

        $this->actingAs($support, 'admin')->get(route('admin.contributions.revenue.index'))->assertForbidden();
        $this->actingAs($support, 'admin')->get(route('admin.contributions.revenue.show', $event))->assertForbidden();
        $this->actingAs($support, 'admin')
            ->post(route('admin.contributions.revenue.payouts.store', $event), [
                'amount' => '50.00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_admin_sees_collected_totals_on_both_pages(): void
    {
        $event = $this->contributingEvent();
        $this->completedPayment($event, '60.00');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.contributions.revenue.index'))
            ->assertOk()
            ->assertSee($event->name)
            ->assertSee('K60.00', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.contributions.revenue.show', $event))
            ->assertOk()
            ->assertSee('K60.00', false);
    }

    public function test_admin_can_record_a_payout_and_it_appears_on_both_pages(): void
    {
        $event = $this->contributingEvent();
        $this->completedPayment($event, '100.00');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.contributions.revenue.payouts.store', $event), [
                'amount' => '40.00',
                'paid_on' => now()->toDateString(),
                'note' => 'Mobile money transfer',
            ])
            ->assertRedirect(route('admin.contributions.revenue.show', $event));

        $this->assertDatabaseHas('contribution_payouts', [
            'event_id' => $event->id,
            'amount' => '40.00',
            'note' => 'Mobile money transfer',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.contributions.revenue.show', $event))
            ->assertOk()
            ->assertSee('K60.00', false) // remaining pending payable
            ->assertSee('Mobile money transfer');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.contributions.revenue.index'))
            ->assertOk()
            ->assertSee('K60.00', false);
    }

    public function test_payout_exceeding_the_pending_balance_is_rejected(): void
    {
        $event = $this->contributingEvent();
        $this->completedPayment($event, '60.00');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.contributions.revenue.payouts.store', $event), [
                'amount' => '61.00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertRedirect(route('admin.contributions.revenue.show', $event))
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('contribution_payouts', 0);
    }

    public function test_a_second_payout_cannot_exceed_what_remains_after_the_first(): void
    {
        $event = $this->contributingEvent();
        $this->completedPayment($event, '100.00');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post(route('admin.contributions.revenue.payouts.store', $event), [
            'amount' => '70.00',
            'paid_on' => now()->toDateString(),
        ])->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.contributions.revenue.payouts.store', $event), [
                'amount' => '31.00',
                'paid_on' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('contribution_payouts', 1);
    }

    public function test_admin_can_export_completed_payments_as_csv(): void
    {
        $event = $this->contributingEvent(['name' => 'Charity Gala']);

        $contribution = EventContribution::factory()->for($event)->create([
            'contributor_name' => 'John Banda',
            'contributor_phone' => '0977654321',
            'contributor_email' => 'john@example.com',
            'target_amount' => '100.00',
            'amount_paid' => '60.00',
        ]);
        ContributionPayment::factory()->for($contribution, 'contribution')->create([
            'amount' => '60.00',
            'payment_method' => 'mobile_money',
            'payment_reference' => 'CTBP-TEST-001',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        // A pending payment for the same pledge must not appear in the export.
        ContributionPayment::factory()->for($contribution, 'contribution')->create([
            'amount' => '40.00',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.contributions.revenue.export', $event));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($response->streamedContent()))));

        $this->assertSame(
            ['Date', 'Contributor', 'Phone', 'Email', 'Amount', 'Method', 'Reference'],
            $rows[0]
        );
        $this->assertCount(2, $rows); // header + the one completed payment
        $this->assertSame('John Banda', $rows[1][1]);
        $this->assertSame('0977654321', $rows[1][2]);
        $this->assertSame('john@example.com', $rows[1][3]);
        $this->assertSame('60.00', $rows[1][4]);
        $this->assertSame('mobile_money', $rows[1][5]);
        $this->assertSame('CTBP-TEST-001', $rows[1][6]);
    }
}
