<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Support\TicketingSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_store_defaults_to_invitation_product(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Garden Party',
            'event_type' => 'birthday',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:00',
        ])->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(EventProductKind::Invitation, $event->product_kind);
        $this->assertSame(TicketingStatus::NotApplicable, $event->ticketing_status);
    }

    public function test_store_creates_a_ticketed_draft_without_spending_a_credit(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Festival',
            'event_type' => 'corporate',
            'product_kind' => EventProductKind::Ticketed->value,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '18:00',
        ])->assertRedirect();

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(EventProductKind::Ticketed, $event->product_kind);
        $this->assertSame(TicketingStatus::Draft, $event->ticketing_status);
        $this->assertSame(CommissionMode::Absorb, $event->commission_mode);
        $this->assertTrue((bool) $event->is_public);
        $this->assertFalse((bool) $event->is_published);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_owner_can_view_ticketed_event_pages(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketType::factory()->for($event)->create(['name' => 'VIP']);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Tickets', false)
            ->assertSee('Ticketed event', false);

        $this->actingAs($user)
            ->get(route('events.ticket-types.index', $event))
            ->assertOk()
            ->assertSee('VIP', false)
            ->assertSee('EventHost / Lenco', false)
            ->assertSee('EventHost Ticketing Commission', false);
    }

    public function test_invitation_event_has_no_ticket_types_page(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('events.ticket-types.index', $event))
            ->assertNotFound();
    }

    public function test_owner_can_create_a_ticket_type(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->post(route('events.ticket-types.store', $event), $this->ticketPayload())
            ->assertRedirect(route('events.ticket-types.index', $event));

        $this->assertDatabaseHas('ticket_types', [
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price' => '200.00',
        ]);
    }

    public function test_non_owner_cannot_manage_ticket_types(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($intruder)
            ->post(route('events.ticket-types.store', $event), $this->ticketPayload())
            ->assertForbidden();
    }

    public function test_submit_requires_an_active_ticket_type(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->post(route('events.ticketing.submit', $event))
            ->assertRedirect(route('events.ticket-types.index', $event))
            ->assertSessionHasErrors('ticketing');

        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);
    }

    public function test_owner_can_submit_a_ticketed_event_for_review(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketType::factory()->for($event)->create();

        $this->actingAs($user)
            ->post(route('events.ticketing.submit', $event))
            ->assertRedirect(route('events.ticket-types.index', $event))
            ->assertSessionHas('status', 'ticketing-submitted');

        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);
        $this->assertNotNull($event->fresh()->ticketing_submitted_at);
    }

    public function test_owner_can_set_commission_mode_before_approval(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->patch(route('events.ticketing.update', $event), [
                'commission_mode' => CommissionMode::PassThrough->value,
            ])
            ->assertRedirect(route('events.ticket-types.index', $event));

        $this->assertSame(CommissionMode::PassThrough, $event->fresh()->commission_mode);
    }

    public function test_publishing_a_ticketed_event_is_blocked(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.ticket-types.index', $event))
            ->assertSessionHasErrors('publish');

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_admin_publish_toggle_does_not_publish_ticketed_events(): void
    {
        $owner = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.events.show', $event))
            ->patch(route('admin.events.publish', $event), ['is_published' => true])
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHasErrors('is_published');

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);
        $this->assertSame(1, $owner->fresh()->event_credits);
    }

    public function test_admin_approval_publishes_without_spending_a_credit(): void
    {
        $owner = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'ticketing_status' => TicketingStatus::PendingReview,
            'ticketing_submitted_at' => now(),
        ]);
        TicketType::factory()->for($event)->create();

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $payoutOn = $event->event_date->format('Y-m-d');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.approve', $event), [
                'agreed_payout_on' => $payoutOn,
            ])
            ->assertRedirect(route('admin.ticketing.show', $event));

        $event->refresh();
        $this->assertSame(TicketingStatus::Approved, $event->ticketing_status);
        $this->assertTrue((bool) $event->is_published);
        $this->assertTrue((bool) $event->is_public);
        $this->assertSame($payoutOn, $event->agreed_payout_on?->format('Y-m-d'));
        $this->assertSame(0, $owner->fresh()->event_credits);
        $this->assertTrue($event->ticketSalesAreApproved());
    }

    public function test_support_can_view_but_not_approve_ticketing(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'ticketing_status' => TicketingStatus::PendingReview,
            'ticketing_submitted_at' => now(),
        ]);

        $support = Admin::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support, 'admin')
            ->get(route('admin.ticketing.index'))
            ->assertOk();

        $this->actingAs($support, 'admin')
            ->post(route('admin.ticketing.approve', $event))
            ->assertForbidden();

        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);
    }

    public function test_admin_can_reject_and_organizer_can_resubmit(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'ticketing_status' => TicketingStatus::PendingReview,
            'ticketing_submitted_at' => now(),
        ]);
        TicketType::factory()->for($event)->create();

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.reject', $event), [
                'ticketing_rejection_note' => 'Need a clearer venue and refund policy.',
            ])
            ->assertRedirect(route('admin.ticketing.show', $event));

        $event->refresh();
        $this->assertSame(TicketingStatus::Rejected, $event->ticketing_status);
        $this->assertSame('Need a clearer venue and refund policy.', $event->ticketing_rejection_note);

        $this->actingAs($owner)
            ->post(route('events.ticketing.submit', $event))
            ->assertSessionHas('status', 'ticketing-submitted');

        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);
        $this->assertNull($event->fresh()->ticketing_rejection_note);
    }

    public function test_commission_defaults_to_five_percent(): void
    {
        $this->assertSame('5.00', TicketingSettings::commissionPercent());
        $this->assertSame('0.00', TicketingSettings::cancellationFeePercent());
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'General Admission',
            'description' => 'Standing room',
            'price' => '200',
            'quantity' => '100',
            'min_per_order' => '1',
            'max_per_order' => '6',
            'is_active' => '1',
            'sort_order' => '0',
        ], $overrides);
    }
}
