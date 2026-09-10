<?php

namespace Tests\Feature;

use App\Enums\ContributionStatus;
use App\Models\Admin;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use App\Services\LencoService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Phase 1 of plans/contributions.md — admin enable/amount, guest pledge +
 * installment payments, completion. Twin of TicketPurchaseFlowTest, minus
 * the cart/hold step.
 */
class EventContributionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['services.lenco.api_secret_key' => 'test-contribution-secret']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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
        return Event::factory()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ], $overrides));
    }

    public function test_contribute_page_404s_when_admin_has_not_enabled_contributions(): void
    {
        $event = Event::factory()->create(['is_published' => true, 'is_public' => true]);

        $this->get(route('events.public.contribute', $event->slug))->assertNotFound();
    }

    public function test_admin_can_enable_contributions_and_set_the_amount(): void
    {
        $event = Event::factory()->create(['is_published' => true, 'is_public' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->patch(route('admin.events.contribution.update', $event), [
                'contribution_enabled' => '1',
                'contribution_amount' => '250.00',
            ])
            ->assertRedirect(route('admin.events.show', $event));

        $event->refresh();
        $this->assertTrue($event->contribution_enabled);
        $this->assertSame('250.00', (string) $event->contribution_amount);
        $this->assertTrue($event->acceptsContributions());
    }

    public function test_support_role_cannot_manage_contributions(): void
    {
        $event = Event::factory()->create(['is_published' => true, 'is_public' => true]);

        $this->actingAs($this->support(), 'admin')
            ->patch(route('admin.events.contribution.update', $event), [
                'contribution_enabled' => '1',
                'contribution_amount' => '250.00',
            ])
            ->assertForbidden();
    }

    public function test_amount_is_required_when_enabling(): void
    {
        $event = Event::factory()->create(['is_published' => true, 'is_public' => true]);

        $this->actingAs($this->admin(), 'admin')
            ->patch(route('admin.events.contribution.update', $event), [
                'contribution_enabled' => '1',
            ])
            ->assertSessionHasErrors('contribution_amount');
    }

    public function test_guest_can_pay_the_full_amount_in_one_installment(): void
    {
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ctb_1',
            'lencoReference' => 'LEN-C1',
            'status' => 'successful',
            'amount' => 100.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Jane Guest',
            'phone' => '0961234567',
            'amount' => '100.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $contribution = EventContribution::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(ContributionStatus::Completed, $contribution->status);
        $this->assertSame('100.00', (string) $contribution->amount_paid);
        $this->assertNotNull($contribution->completed_at);

        // Host-facing read-only summary on their own event page.
        $this->actingAs($event->user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Contributions')
            ->assertSee('K100.00', false);
    }

    public function test_guest_can_split_the_amount_into_two_installments(): void
    {
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ctb_2',
            'lencoReference' => 'LEN-C2',
            'status' => 'successful',
            'amount' => 40.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $first = $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Partial Payer',
            'phone' => '0966000111',
            'amount' => '40.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966000111',
        ])->assertOk()->json();

        $contribution = EventContribution::query()->where('reference', $first['reference'])->firstOrFail();
        $this->assertSame(ContributionStatus::Partial, $contribution->status);
        $this->assertSame('40.00', (string) $contribution->amount_paid);
        $this->assertSame(60.0, $contribution->remainingAmount());

        $lenco2 = Mockery::mock(LencoService::class);
        $lenco2->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ctb_3',
            'lencoReference' => 'LEN-C3',
            'status' => 'successful',
            'amount' => 60.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco2);

        $this->postJson(route('contributions.pay', $contribution->reference), [
            'amount' => '60.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966000111',
        ])->assertOk()->assertJsonPath('success', true);

        $contribution->refresh();
        $this->assertSame(ContributionStatus::Completed, $contribution->status);
        $this->assertSame('100.00', (string) $contribution->amount_paid);
        $this->assertCount(2, ContributionPayment::query()->where('event_contribution_id', $contribution->id)->get());
    }

    public function test_returning_contributor_is_matched_by_phone_not_forked_into_a_new_pledge(): void
    {
        $event = $this->contributingEvent();

        // Each installment settles immediately (status: successful) so the
        // second request isn't blocked by the "payment already in progress"
        // guard — that guard is covered separately; this test only checks
        // phone matching.
        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ctb_x',
            'lencoReference' => 'LEN-CX',
            'status' => 'successful',
            'amount' => 30.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Repeat Payer',
            'phone' => '+260966222333',
            'amount' => '30.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966222333',
        ])->assertOk();

        $lenco2 = Mockery::mock(LencoService::class);
        $lenco2->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_ctb_y',
            'lencoReference' => 'LEN-CY',
            'status' => 'successful',
            'amount' => 20.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco2);

        // Same phone, different formatting — must resume the same pledge.
        $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Repeat Payer',
            'phone' => '0966222333',
            'amount' => '20.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966222333',
        ])->assertOk();

        $this->assertSame(1, EventContribution::query()->where('event_id', $event->id)->count());

        $contribution = EventContribution::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('50.00', (string) $contribution->amount_paid);
    }

    public function test_amount_over_the_remaining_balance_is_rejected(): void
    {
        $event = $this->contributingEvent();

        $response = $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Over Payer',
            'phone' => '0966111222',
            'amount' => '150.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966111222',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        // The pledge itself is created (startOrResume happens before the
        // amount is checked) — it just has no successful payment against it.
        $contribution = EventContribution::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame('0.00', (string) $contribution->amount_paid);
        $this->assertSame(ContributionStatus::Pending, $contribution->status);
        $this->assertSame(0, ContributionPayment::query()->where('event_contribution_id', $contribution->id)->count());
    }
}
