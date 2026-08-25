<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use App\Notifications\TicketingApprovedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminTicketedEventCreateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function adminWithApprove(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function supportAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('support');

        return $admin;
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketedPayload(User $owner, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $owner->id,
            'product_kind' => EventProductKind::Ticketed->value,
            'name' => 'Client Concert',
            'event_type' => 'concert',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '19:00',
            'description' => 'Admin-created ticketed event',
            'venue' => 'Lusaka Arena',
        ], $overrides);
    }

    public function test_admin_can_open_the_create_form_and_support_cannot(): void
    {
        $this->actingAs($this->adminWithApprove(), 'admin')
            ->get(route('admin.ticketing.create'))
            ->assertOk()
            ->assertSee('Create ticketed event', false)
            ->assertSee('name="user_id"', false);

        $this->actingAs($this->supportAdmin(), 'admin')
            ->get(route('admin.ticketing.create'))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_ticketed_draft_owned_by_the_chosen_client(): void
    {
        $owner = User::factory()->withoutCredits()->create();
        $admin = $this->adminWithApprove();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.store'), $this->ticketedPayload($owner))
            ->assertRedirect();

        $event = Event::query()->where('name', 'Client Concert')->first();
        $this->assertNotNull($event);
        $this->assertSame($owner->id, $event->user_id);
        $this->assertSame(EventProductKind::Ticketed, $event->product_kind);
        $this->assertSame(TicketingStatus::Draft, $event->ticketing_status);
        $this->assertSame(CommissionMode::Absorb, $event->commission_mode);
        $this->assertTrue((bool) $event->is_public);
        $this->assertFalse((bool) $event->is_published);
        $this->assertSame(0, $owner->fresh()->event_credits);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ticketing.show', $event))
            ->assertOk()
            ->assertSee('Client Concert', false);
    }

    public function test_support_cannot_create_a_ticketed_event(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->supportAdmin(), 'admin')
            ->post(route('admin.ticketing.store'), $this->ticketedPayload($owner))
            ->assertForbidden();

        $this->assertDatabaseMissing('events', ['name' => 'Client Concert']);
    }

    public function test_admin_create_always_stores_as_ticketed_even_if_invitation_is_posted(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->post(route('admin.ticketing.store'), $this->ticketedPayload($owner, [
                'product_kind' => EventProductKind::Invitation->value,
            ]))
            ->assertRedirect();

        $event = Event::query()->where('name', 'Client Concert')->first();
        $this->assertNotNull($event);
        $this->assertSame(EventProductKind::Ticketed, $event->product_kind);
        $this->assertSame(TicketingStatus::Draft, $event->ticketing_status);
    }

    public function test_invitation_event_types_are_rejected_on_admin_ticketed_create(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->post(route('admin.ticketing.store'), $this->ticketedPayload($owner, [
                'event_type' => 'wedding',
            ]))
            ->assertSessionHasErrors('event_type');

        $this->assertDatabaseMissing('events', ['name' => 'Client Concert']);
    }

    public function test_suspended_user_cannot_be_assigned_as_owner(): void
    {
        $owner = User::factory()->suspended()->create();

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->post(route('admin.ticketing.store'), $this->ticketedPayload($owner))
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('events', ['name' => 'Client Concert']);
    }

    public function test_create_form_preselects_user_from_query_string(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->get(route('admin.ticketing.create', ['user' => $owner->id]))
            ->assertOk()
            ->assertSee('value="'.$owner->id.'"', false);
    }

    public function test_admin_can_add_a_ticket_type_and_activate_from_draft(): void
    {
        Notification::fake();

        $owner = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'cover_image' => 'events/hero.webp',
        ]);

        $admin = $this->adminWithApprove();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.ticket-types.store', $event), [
                'name' => 'General',
                'price' => '150.00',
                'min_per_order' => 1,
                'max_per_order' => 4,
                'is_active' => '1',
                'sort_order' => 0,
            ])
            ->assertRedirect(route('admin.ticketing.show', $event));

        $this->assertDatabaseHas('ticket_types', [
            'event_id' => $event->id,
            'name' => 'General',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.approve', $event))
            ->assertRedirect(route('admin.ticketing.show', $event));

        $event->refresh();
        $this->assertSame(TicketingStatus::Approved, $event->ticketing_status);
        $this->assertTrue((bool) $event->is_published);
        $this->assertTrue((bool) $event->is_public);
        $this->assertNotNull($event->ticketing_submitted_at);
        $this->assertSame(0, $owner->fresh()->event_credits);

        Notification::assertSentTo($owner, TicketingApprovedNotification::class);
    }

    public function test_activating_from_draft_without_hero_fails(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'cover_image' => null,
        ]);
        TicketType::factory()->for($event)->create();

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->from(route('admin.ticketing.show', $event))
            ->post(route('admin.ticketing.approve', $event))
            ->assertRedirect(route('admin.ticketing.show', $event))
            ->assertSessionHasErrors('ticketing');

        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);
        $this->assertFalse((bool) $event->fresh()->is_published);
    }

    public function test_activating_from_draft_without_an_active_ticket_type_fails(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'cover_image' => 'events/hero.webp',
        ]);
        TicketType::factory()->for($event)->create(['is_active' => false]);

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->from(route('admin.ticketing.show', $event))
            ->post(route('admin.ticketing.approve', $event))
            ->assertRedirect(route('admin.ticketing.show', $event))
            ->assertSessionHasErrors('ticketing');

        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);
    }

    public function test_admin_can_update_commission_mode_before_approval(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($this->adminWithApprove(), 'admin')
            ->patch(route('admin.ticketing.commission', $event), [
                'commission_mode' => CommissionMode::PassThrough->value,
            ])
            ->assertRedirect(route('admin.ticketing.show', $event));

        $this->assertSame(CommissionMode::PassThrough, $event->fresh()->commission_mode);
    }

    public function test_host_sees_admin_created_event_on_their_dashboard(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'name' => 'White Glove Night',
        ]);

        $this->actingAs($owner)
            ->get(route('events.index', ['kind' => 'ticketed']))
            ->assertOk()
            ->assertSee('White Glove Night', false);
    }

    public function test_host_create_still_uses_shared_ticketed_creator_defaults(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('events.store'), [
                'product_kind' => EventProductKind::Ticketed->value,
                'name' => 'Host Concert',
                'event_type' => 'concert',
                'event_date' => now()->addWeek()->format('Y-m-d'),
                'event_time' => '20:00',
            ])
            ->assertRedirect();

        $event = Event::query()->where('name', 'Host Concert')->first();
        $this->assertNotNull($event);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame(TicketingStatus::Draft, $event->ticketing_status);
        $this->assertSame(CommissionMode::Absorb, $event->commission_mode);
        $this->assertTrue((bool) $event->is_public);
    }
}
