<?php

namespace Tests\Feature;

use App\Enums\PublicRegistrationStatus;
use App\Exceptions\PublicRegistrationException;
use App\Models\Admin;
use App\Models\Event;
use App\Models\User;
use App\Services\PublicRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 1 of plans/public-private-portals.md Phase 4c — the state machine and
 * defaults for a free-registration public event's admin approval, and its
 * exclusion from the credit-publish path. No payment, no admin UI, no
 * notifications yet (Steps 2–4) — those are covered elsewhere once built.
 */
class PublicRegistrationApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::factory()->create();
    }

    public function test_a_new_free_registration_event_defaults_to_draft(): void
    {
        $event = Event::factory()->publicAudience()->create();

        $this->assertTrue($event->isFreeRegistration());
        $this->assertSame(PublicRegistrationStatus::Draft, $event->public_registration_status);
    }

    public function test_a_private_event_is_not_applicable(): void
    {
        $event = Event::factory()->privateAudience()->create();

        $this->assertFalse($event->isFreeRegistration());
        // The default lives only in the DB column, never assigned in PHP for
        // this path — read back the row rather than the in-memory instance.
        $this->assertSame(PublicRegistrationStatus::NotApplicable, $event->fresh()->public_registration_status);
    }

    public function test_a_ticketed_event_is_not_applicable(): void
    {
        $event = Event::factory()->ticketed()->create();

        $this->assertFalse($event->isFreeRegistration());
        $this->assertSame(PublicRegistrationStatus::NotApplicable, $event->fresh()->public_registration_status);
    }

    public function test_an_explicit_status_on_create_is_not_overridden(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
        ]);

        $this->assertSame(PublicRegistrationStatus::Approved, $event->fresh()->public_registration_status);
    }

    public function test_submit_moves_draft_to_pending_review(): void
    {
        $event = Event::factory()->publicAudience()->create();

        app(PublicRegistrationService::class)->submit($event);

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::PendingReview, $fresh->public_registration_status);
        $this->assertNotNull($fresh->public_registration_submitted_at);
    }

    public function test_submit_moves_rejected_back_to_pending_review_and_clears_the_note(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Rejected,
            'public_registration_rejection_note' => 'Missing venue details.',
        ]);

        app(PublicRegistrationService::class)->submit($event);

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::PendingReview, $fresh->public_registration_status);
        $this->assertNull($fresh->public_registration_rejection_note);
    }

    public function test_submit_refuses_an_already_pending_event(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::PendingReview,
        ]);

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->submit($event);
    }

    public function test_submit_refuses_an_already_approved_event(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
        ]);

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->submit($event);
    }

    public function test_submit_refuses_a_private_event(): void
    {
        $event = Event::factory()->privateAudience()->create();

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->submit($event);
    }

    public function test_submit_refuses_a_ticketed_event(): void
    {
        $event = Event::factory()->ticketed()->create();

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->submit($event);
    }

    public function test_approve_sets_the_quote_and_moves_to_approved(): void
    {
        $event = Event::factory()->publicAudience()->create();
        $admin = $this->admin();

        app(PublicRegistrationService::class)->approve($event, $admin, 350.00);

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::Approved, $fresh->public_registration_status);
        $this->assertSame('350.00', (string) $fresh->public_registration_quote_amount);
        $this->assertSame($admin->id, $fresh->public_registration_reviewed_by);
        $this->assertNotNull($fresh->public_registration_reviewed_at);
    }

    /**
     * Unlike ticketed approval, approving a free-registration event does not
     * publish it — the host still owes the quoted amount.
     */
    public function test_approve_does_not_publish_the_event(): void
    {
        $event = Event::factory()->publicAudience()->create(['is_published' => false]);

        app(PublicRegistrationService::class)->approve($event, $this->admin(), 100.00);

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertTrue($event->fresh()->awaitingPublicRegistrationPayment());
    }

    public function test_approve_can_activate_straight_from_draft(): void
    {
        $event = Event::factory()->publicAudience()->create();

        app(PublicRegistrationService::class)->approve($event, $this->admin(), 200.00);

        $this->assertSame(PublicRegistrationStatus::Approved, $event->fresh()->public_registration_status);
    }

    public function test_approve_rejects_a_zero_quote(): void
    {
        $event = Event::factory()->publicAudience()->create();

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->approve($event, $this->admin(), 0.0);
    }

    public function test_approve_rejects_a_negative_quote(): void
    {
        $event = Event::factory()->publicAudience()->create();

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->approve($event, $this->admin(), -5.0);
    }

    public function test_reapproving_an_unpaid_approved_event_updates_the_quote(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
            'public_registration_quote_amount' => 100.00,
        ]);

        app(PublicRegistrationService::class)->approve($event, $this->admin(), 150.00);

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::Approved, $fresh->public_registration_status);
        $this->assertSame('150.00', (string) $fresh->public_registration_quote_amount);
        $this->assertNull($fresh->public_registration_quote_paid_at);
    }

    /**
     * Once the quote is paid the event is live and money has moved — approve()
     * must refuse to touch it again rather than silently rewriting a settled
     * price.
     */
    public function test_a_paid_event_cannot_be_re_approved(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::Approved,
            'public_registration_quote_amount' => 100.00,
            'public_registration_quote_paid_at' => now(),
        ]);

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->approve($event, $this->admin(), 150.00);
    }

    public function test_reject_requires_pending_review(): void
    {
        $event = Event::factory()->publicAudience()->create();

        $this->expectException(PublicRegistrationException::class);

        app(PublicRegistrationService::class)->reject($event, $this->admin(), 'Not eligible.');
    }

    public function test_reject_moves_pending_review_to_rejected_with_a_note(): void
    {
        $event = Event::factory()->publicAudience()->create([
            'public_registration_status' => PublicRegistrationStatus::PendingReview,
        ]);
        $admin = $this->admin();

        app(PublicRegistrationService::class)->reject($event, $admin, 'Incomplete venue details.');

        $fresh = $event->fresh();
        $this->assertSame(PublicRegistrationStatus::Rejected, $fresh->public_registration_status);
        $this->assertSame('Incomplete venue details.', $fresh->public_registration_rejection_note);
        $this->assertSame($admin->id, $fresh->public_registration_reviewed_by);
    }

    public function test_publishing_a_free_registration_event_is_blocked_and_spends_no_credit(): void
    {
        $user = User::factory()->withCredits(5)->create();
        $event = Event::factory()->for($user)->publicAudience()->create(['is_published' => false]);

        $this->actingAs($user)
            ->patch(route('events.publish', $event))
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHasErrors('publish');

        $this->assertFalse((bool) $event->fresh()->is_published);
        $this->assertSame(5, $user->fresh()->event_credits);
    }
}
