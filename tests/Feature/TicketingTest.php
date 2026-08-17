<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\StagedMedia;
use App\Models\TicketType;
use App\Models\User;
use App\Support\TicketingSettings;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TicketingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_starts_by_choosing_how_people_join(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('events.create'))
            ->assertOk()
            ->assertSee('Invitation / RSVP', false)
            ->assertSee('Guests respond on a personal or public invite. Publishing uses 1 event credit.', false)
            ->assertSee('Ticketed event', false)
            ->assertSee('Sell tickets through EventHost checkout (Lenco). EventHost reviews sales before they go live — no event credit.', false)
            ->assertDontSee('name="name"', false)
            ->assertSee(route('events.create', ['kind' => 'invitation']), false)
            ->assertSee(route('events.create', ['kind' => 'ticketed']), false);
    }

    public function test_create_details_form_locks_the_chosen_product_kind(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('events.create', ['kind' => 'invitation']))
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('Invitation / RSVP', false)
            ->assertSee('Change', false)
            ->assertDontSee('Ticketed event', false);

        $this->actingAs($user)
            ->get(route('events.create', ['kind' => 'ticketed']))
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('Ticketed event', false)
            ->assertDontSee('Cover image', false)
            ->assertDontSee('Guest settings', false)
            ->assertDontSee('RSVP deadline', false)
            ->assertDontSee('Invitation / RSVP', false);
    }

    public function test_store_requires_a_product_kind(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Garden Party',
            'event_type' => 'birthday',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:00',
        ])->assertSessionHasErrors('product_kind');
    }

    public function test_store_creates_an_invitation_product(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Garden Party',
            'event_type' => 'birthday',
            'product_kind' => EventProductKind::Invitation->value,
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
            ->assertSee('Ticketed event', false)
            ->assertDontSee(route('events.preview', $event), false);

        $this->actingAs($user)
            ->get(route('events.ticket-types.index', $event))
            ->assertOk()
            ->assertSee('VIP', false)
            ->assertSee('commission', false)
            ->assertSee('Mobile Money / Bank Transfer', false)
            ->assertDontSee('EventHost / Lenco', false);
    }

    public function test_invitation_event_has_no_ticket_types_page(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('events.ticket-types.index', $event))
            ->assertNotFound();
    }

    public function test_ticketed_event_has_no_guest_list(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->get(route('events.guests.index', $event))
            ->assertNotFound();
    }

    public function test_ticketed_event_cannot_store_a_guest(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->post(route('events.guests.store', $event), ['name' => 'Alice'])
            ->assertNotFound();

        $this->assertSame(0, Guest::query()->where('event_id', $event->id)->count());
    }

    public function test_ticketed_event_404s_on_the_guest_checkin_scanner(): void
    {
        $user = User::factory()->pro()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->get(route('events.checkin.scan', $event))
            ->assertNotFound();
    }

    public function test_open_rsvp_404s_for_a_ticketed_event(): void
    {
        $event = Event::factory()->ticketed()->published()->create([
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
        ]);

        $this->get(route('rsvp.open.show', $event->slug))->assertNotFound();
        $this->post(route('rsvp.open.store', $event->slug), [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => 'accepted',
        ])->assertNotFound();

        $this->assertSame(0, Guest::query()->where('event_id', $event->id)->count());
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
            'cover_image' => 'events/hero.webp',
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

    public function test_admin_cannot_approve_without_a_hero_image(): void
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
            ->from(route('admin.ticketing.show', $event))
            ->post(route('admin.ticketing.approve', $event))
            ->assertRedirect(route('admin.ticketing.show', $event))
            ->assertSessionHasErrors('ticketing');

        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);
        $this->assertFalse((bool) $event->fresh()->is_published);
    }

    public function test_admin_can_upload_a_ticketed_hero_image(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick is required for hero image processing.');
        }

        Storage::fake('public');

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create([
            'ticketing_status' => TicketingStatus::PendingReview,
            'ticketing_submitted_at' => now(),
        ]);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.ticketing.show', $event))
            ->assertOk()
            ->assertSee('Hero image', false)
            ->assertSee('No hero image yet', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ticketing.hero', $event), [
                'hero_image' => UploadedFile::fake()->image('hero.jpg', 1400, 800),
            ])
            ->assertRedirect(route('admin.ticketing.show', $event))
            ->assertSessionHas('status', 'ticketing-hero-updated');

        $event->refresh();
        $this->assertNotNull($event->cover_image);
        $this->assertMatchesRegularExpression('/\.webp$/', $event->cover_image);
        Storage::disk('public')->assertExists($event->cover_image);
    }

    public function test_support_cannot_upload_a_ticketed_hero_image(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $support = Admin::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support, 'admin')
            ->post(route('admin.ticketing.hero', $event), [
                'hero_image' => UploadedFile::fake()->image('hero.jpg', 1400, 800),
            ])
            ->assertForbidden();

        $this->assertNull($event->fresh()->cover_image);
    }

    public function test_ticketed_store_ignores_a_host_cover_upload(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Festival',
            'event_type' => 'corporate',
            'product_kind' => EventProductKind::Ticketed->value,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '18:00',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 1400, 800),
        ]);

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($event->cover_image);
    }

    public function test_ticketed_store_ignores_invitation_guest_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Festival',
            'event_type' => 'corporate',
            'product_kind' => EventProductKind::Ticketed->value,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '18:00',
            'is_public' => '0',
            'allow_plus_one' => '1',
            'rsvp_deadline' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'guest_limit' => '50',
        ]);

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertTrue((bool) $event->is_public);
        $this->assertFalse((bool) $event->allow_plus_one);
        $this->assertNull($event->rsvp_deadline);
        $this->assertNull($event->guest_limit);
    }

    public function test_ticketed_event_update_ignores_invitation_guest_settings(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create([
            'is_public' => true,
            'allow_plus_one' => false,
            'show_guest_list' => false,
            'rsvp_deadline' => null,
            'guest_limit' => null,
        ]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => '18:00',
            'is_public' => '0',
            'allow_plus_one' => '1',
            'show_guest_list' => '1',
            'rsvp_deadline' => now()->addDays(3)->format('Y-m-d\TH:i'),
            'guest_limit' => '50',
        ])->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertTrue((bool) $event->is_public);
        $this->assertFalse((bool) $event->allow_plus_one);
        $this->assertFalse((bool) $event->show_guest_list);
        $this->assertNull($event->rsvp_deadline);
        $this->assertNull($event->guest_limit);
    }

    public function test_host_cannot_overwrite_ticketed_hero_via_event_update(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Storage::disk('public')->put('events/admin-hero.webp', 'x');
        $event = Event::factory()->for($user)->ticketed()->create([
            'cover_image' => 'events/admin-hero.webp',
        ]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => '18:00',
            'cover_image' => UploadedFile::fake()->image('sneaky.jpg', 1400, 800),
        ])->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertSame('events/admin-hero.webp', $event->cover_image);
    }

    public function test_host_cannot_stage_a_cover_on_a_ticketed_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->postJson(route('events.media.stage', $event), [
                'slot' => StagedMedia::SLOT_COVER,
                'file' => UploadedFile::fake()->image('cover.jpg', 1400, 800),
            ])
            ->assertUnprocessable();

        $this->assertSame(0, StagedMedia::query()->where('event_id', $event->id)->count());
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
                'ticketing_rejection_note' => 'Need a clearer venue.',
            ])
            ->assertRedirect(route('admin.ticketing.show', $event));

        $event->refresh();
        $this->assertSame(TicketingStatus::Rejected, $event->ticketing_status);
        $this->assertSame('Need a clearer venue.', $event->ticketing_rejection_note);

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

    public function test_ticketed_store_skips_choose_template_and_lands_on_edit(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Festival',
            'event_type' => 'corporate',
            'product_kind' => EventProductKind::Ticketed->value,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'event_time' => '18:00',
        ]);

        $event = Event::query()->where('user_id', $user->id)->firstOrFail();

        $response->assertRedirect(route('events.edit', $event));
        $response->assertSessionHas('status', 'draft-saved');
    }

    public function test_choose_template_redirects_ticketed_events_to_edit(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->get(route('events.choose-template', $event))
            ->assertRedirect(route('events.edit', $event));
    }

    public function test_ticketed_edit_page_has_no_layout_picker(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->get(route('events.edit', $event))
            ->assertOk()
            ->assertDontSee('Choose invitation layout', false)
            ->assertDontSee('Cover image', false)
            ->assertDontSee('Guest settings', false)
            ->assertDontSee('RSVP deadline', false)
            ->assertDontSee('Preview event', false)
            ->assertDontSee(route('events.preview', $event), false);
    }

    public function test_ticketed_public_page_renders_the_fixed_template(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create([
            'is_published' => true,
            'ticketing_status' => TicketingStatus::Approved,
        ]);
        TicketType::factory()->for($event)->create(['name' => 'VIP', 'price' => '350.00']);

        $response = $this->get(route('events.public', $event->slug));

        $response->assertOk();
        $response->assertSee($event->name, false);
        $response->assertSee('VIP', false);
        $response->assertSee('K350.00', false);
        $response->assertSee('Buy tickets', false);
        $response->assertDontSee('evt-invitation', false);
    }

    public function test_ticketed_preview_redirects_to_edit(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketType::factory()->for($event)->create(['name' => 'General']);

        $this->actingAs($user)
            ->get(route('events.preview', $event))
            ->assertRedirect(route('events.edit', $event));
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
