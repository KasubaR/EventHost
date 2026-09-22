<?php

namespace Tests\Feature;

use App\Enums\PublicRegistrationStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\User;
use App\Notifications\PublicRegistrationApprovedNotification;
use App\Notifications\PublicRegistrationRejectedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Step 3 of plans/public-private-portals.md Phase 4c — the admin approval
 * card on admin/events/show.blade.php (twin of the Contribution admin card,
 * approve/reject mirroring Admin\TicketingController) plus the host-facing
 * submit-for-review action that closes the loop those actions need.
 */
class PublicRegistrationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function pendingEvent(User $owner): Event
    {
        return Event::factory()->for($owner)->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::PendingReview,
            'public_registration_submitted_at' => now(),
        ]);
    }

    // --- Host submit-for-review ---

    public function test_owner_can_submit_a_draft_event_for_review(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $this->actingAs($owner)
            ->post(route('events.public-registration.submit', $event))
            ->assertRedirect(route('events.edit', $event))
            ->assertSessionHas('status', 'public-registration-submitted');

        $this->assertSame(PublicRegistrationStatus::PendingReview, $event->fresh()->public_registration_status);
    }

    public function test_owner_can_resubmit_a_rejected_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Rejected,
            'public_registration_rejection_note' => 'Missing description.',
        ]);

        $this->actingAs($owner)
            ->post(route('events.public-registration.submit', $event))
            ->assertRedirect(route('events.edit', $event))
            ->assertSessionHas('status', 'public-registration-submitted');

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::PendingReview, $fresh->public_registration_status);
        $this->assertNull($fresh->public_registration_rejection_note);
    }

    public function test_submit_refuses_an_event_already_pending(): void
    {
        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $this->actingAs($owner)
            ->post(route('events.public-registration.submit', $event))
            ->assertRedirect(route('events.edit', $event))
            ->assertSessionHasErrors('public_registration');

        $this->assertSame(PublicRegistrationStatus::PendingReview, $event->fresh()->public_registration_status);
    }

    public function test_non_owner_cannot_submit_for_review(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $this->actingAs($stranger)
            ->post(route('events.public-registration.submit', $event))
            ->assertForbidden();
    }

    public function test_submit_404s_for_a_ticketed_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($owner)
            ->post(route('events.public-registration.submit', $event))
            ->assertNotFound();
    }

    // --- Admin approve/reject ---

    public function test_guest_cannot_approve(): void
    {
        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $this->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 300])
            ->assertRedirect('/admin/login');
    }

    public function test_support_cannot_approve_or_reject(): void
    {
        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $support = Admin::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 300])
            ->assertForbidden();

        $this->actingAs($support, 'admin')
            ->post(route('admin.events.public-registration.reject', $event), ['public_registration_rejection_note' => 'No.'])
            ->assertForbidden();
    }

    public function test_admin_can_approve_and_set_a_quote(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 350.00])
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHas('status', 'public-registration-approved');

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::Approved, $fresh->public_registration_status);
        $this->assertEquals(350.00, $fresh->public_registration_quote_amount);
        $this->assertFalse((bool) $fresh->is_published);

        Notification::assertSentTo($owner, PublicRegistrationApprovedNotification::class);
    }

    public function test_admin_can_approve_straight_from_draft(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 200])
            ->assertRedirect(route('admin.events.show', $event));

        $this->assertSame(PublicRegistrationStatus::Approved, $event->fresh()->public_registration_status);
    }

    public function test_admin_cannot_approve_a_zero_quote(): void
    {
        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 0])
            ->assertSessionHasErrors('quote_amount');

        $this->assertSame(PublicRegistrationStatus::PendingReview, $event->fresh()->public_registration_status);
    }

    public function test_admin_can_reject_with_a_note(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.reject', $event), [
                'public_registration_rejection_note' => 'Description is missing key details.',
            ])
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHas('status', 'public-registration-rejected');

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::Rejected, $fresh->public_registration_status);
        $this->assertSame('Description is missing key details.', $fresh->public_registration_rejection_note);

        Notification::assertSentTo($owner, PublicRegistrationRejectedNotification::class);
    }

    public function test_admin_cannot_reject_a_draft_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.reject', $event), [
                'public_registration_rejection_note' => 'No.',
            ])
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHasErrors('public_registration');

        $this->assertSame(PublicRegistrationStatus::Draft, $event->fresh()->public_registration_status);
    }

    public function test_admin_can_re_approve_an_unpaid_approved_event_to_change_the_quote(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
            'public_registration_quote_amount' => 200,
        ]);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 275])
            ->assertRedirect(route('admin.events.show', $event));

        $this->assertEquals(275, $event->fresh()->public_registration_quote_amount);
    }

    public function test_admin_cannot_approve_an_already_paid_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
            'public_registration_quote_amount' => 200,
            'public_registration_quote_paid_at' => now(),
            'is_published' => true,
        ]);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.events.public-registration.approve', $event), ['quote_amount' => 400])
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHasErrors('public_registration');

        $this->assertEquals(200, $event->fresh()->public_registration_quote_amount);
    }

    // --- Admin panel card visibility ---

    public function test_admin_event_show_page_displays_the_public_registration_card(): void
    {
        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSee('Public registration', false)
            ->assertSee('Awaiting EventHost review', false)
            ->assertSee(route('admin.events.public-registration.approve', $event), false)
            ->assertSee(route('admin.events.public-registration.reject', $event), false);
    }

    public function test_admin_event_show_page_hides_the_card_for_a_private_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->privateAudience()->create();

        $admin = Admin::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertDontSee('Public registration', false);
    }

    public function test_support_sees_no_approve_or_reject_actions_on_the_card(): void
    {
        $owner = User::factory()->create();
        $event = $this->pendingEvent($owner);

        $support = Admin::factory()->create();
        $support->assignRole('support');

        $this->actingAs($support, 'admin')
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertDontSee('Public registration', false);
    }
}
