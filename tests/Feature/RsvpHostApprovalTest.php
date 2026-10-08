<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\RsvpAwaitingApprovalNotification;
use App\Notifications\RsvpConfirmationNotification;
use App\Notifications\RsvpRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-host-approval.md — opt-in per-event gate that holds an Accepted
 * RSVP for host review before the guest's confirmation + entry pass go out.
 */
class RsvpHostApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function rsvpPayload(RsvpStatus $status, int $attendeeCount = 1): array
    {
        return [
            'status' => $status->value,
            'attendee_count' => $attendeeCount,
            'message' => null,
        ];
    }

    public function test_accepted_rsvp_behaves_as_today_when_approval_not_required(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'require_rsvp_approval' => false,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_no_approval_needed',
            'email' => 'guest@example.test',
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_no_approval_needed']), $this->rsvpPayload(RsvpStatus::Accepted))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_no_approval_needed']));

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::NotRequired, $rsvp->host_approval_status);

        Notification::assertSentOnDemand(RsvpConfirmationNotification::class);
        Notification::assertSentTo($user, NewRsvpReceivedNotification::class);
        Notification::assertNotSentTo($user, RsvpAwaitingApprovalNotification::class);
    }

    public function test_accepted_rsvp_is_held_pending_when_approval_required(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_awaiting_approval',
            'email' => 'guest@example.test',
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_awaiting_approval']), $this->rsvpPayload(RsvpStatus::Accepted))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_awaiting_approval']));

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Pending, $rsvp->host_approval_status);

        Notification::assertNotSentTo(new AnonymousNotifiable, RsvpConfirmationNotification::class);
        Notification::assertNotSentTo($user, NewRsvpReceivedNotification::class);
        Notification::assertSentTo($user, RsvpAwaitingApprovalNotification::class);

        $this->assertFalse($guest->hasEntryPassFor($rsvp, $event));
    }

    public function test_decline_is_unaffected_by_approval_requirement(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_decline_unaffected',
            'email' => 'guest@example.test',
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_decline_unaffected']), $this->rsvpPayload(RsvpStatus::Declined, 0))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_decline_unaffected']));

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::NotRequired, $rsvp->host_approval_status);

        Notification::assertSentOnDemand(RsvpConfirmationNotification::class);
        Notification::assertSentTo($user, NewRsvpReceivedNotification::class);
    }

    public function test_host_can_approve_a_pending_rsvp(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create(['email' => 'guest@example.test']);
        $rsvp = Rsvp::factory()->forGuest($guest)->accepted()->create([
            'host_approval_status' => RsvpApprovalStatus::Pending,
        ]);

        $this->actingAs($user)
            ->patch(route('events.guests.rsvp.approve', ['event' => $event, 'guest' => $guest]))
            ->assertRedirect();

        $rsvp->refresh();
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status);
        $this->assertNotNull($rsvp->host_reviewed_at);
        $this->assertSame($user->id, $rsvp->host_reviewed_by);

        Notification::assertSentOnDemand(RsvpConfirmationNotification::class);
    }

    public function test_host_can_reject_a_pending_rsvp_with_a_note(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create(['email' => 'guest@example.test']);
        $rsvp = Rsvp::factory()->forGuest($guest)->accepted()->create([
            'host_approval_status' => RsvpApprovalStatus::Pending,
        ]);

        $this->actingAs($user)
            ->patch(route('events.guests.rsvp.reject', ['event' => $event, 'guest' => $guest]), [
                'host_rejection_note' => 'We are at capacity for this date.',
            ])
            ->assertRedirect();

        $rsvp->refresh();
        $this->assertSame(RsvpApprovalStatus::Rejected, $rsvp->host_approval_status);
        $this->assertSame('We are at capacity for this date.', $rsvp->host_rejection_note);

        Notification::assertSentOnDemand(RsvpRejectedNotification::class);
        Notification::assertNotSentTo(new AnonymousNotifiable, RsvpConfirmationNotification::class);

        $this->assertFalse($guest->hasEntryPassFor($rsvp, $event));
    }

    public function test_reject_requires_a_note(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($guest)->accepted()->create([
            'host_approval_status' => RsvpApprovalStatus::Pending,
        ]);

        $this->actingAs($user)
            ->patch(route('events.guests.rsvp.reject', ['event' => $event, 'guest' => $guest]), [
                'host_rejection_note' => '',
            ])
            ->assertSessionHasErrors('host_rejection_note');
    }

    public function test_a_non_owner_cannot_approve_or_reject(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($guest)->accepted()->create([
            'host_approval_status' => RsvpApprovalStatus::Pending,
        ]);

        $this->actingAs($stranger)
            ->patch(route('events.guests.rsvp.approve', ['event' => $event, 'guest' => $guest]))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->patch(route('events.guests.rsvp.reject', ['event' => $event, 'guest' => $guest]), [
                'host_rejection_note' => 'nope',
            ])
            ->assertForbidden();
    }

    public function test_approving_an_already_decided_rsvp_is_refused(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($guest)->accepted()->create([
            'host_approval_status' => RsvpApprovalStatus::Approved,
        ]);

        $this->actingAs($user)
            ->patch(route('events.guests.rsvp.approve', ['event' => $event, 'guest' => $guest]))
            ->assertSessionHasErrors('rsvp_approval');
    }

    public function test_editing_an_approved_rsvp_does_not_reopen_review(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => null,
            'allow_plus_one' => true,
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_stays_approved',
            'email' => 'guest@example.test',
            'plus_one_allowed' => true,
        ]);
        Rsvp::factory()->forGuest($guest)->accepted(1)->create([
            'host_approval_status' => RsvpApprovalStatus::Approved,
            'host_reviewed_at' => now(),
        ]);

        // Guest asks for a plus-one while still Accepted. Approval follows seats (plans/rsvp-status-changes.md
        // Phase 3): the seat the host approved stays approved and keeps its pass, and only the EXTRA seat goes to review.
        $this->post(route('rsvp.token.store', ['token' => 'tok_stays_approved']), $this->rsvpPayload(RsvpStatus::Accepted, 2))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_stays_approved']));

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Pending, $rsvp->host_approval_status);
        $this->assertSame(2, $rsvp->attendee_count);
        $this->assertSame(1, $rsvp->approvedSeatsOnFile());
        $this->assertSame(1, $rsvp->passSeats());

        // Asking for fewer seats than were approved needs no review at all.
        $this->post(route('rsvp.token.store', ['token' => 'tok_stays_approved']), $this->rsvpPayload(RsvpStatus::Accepted, 1));
        $this->assertSame(RsvpApprovalStatus::Approved, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_declining_then_re_accepting_after_a_rejection_stays_rejected(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => null,
            'require_rsvp_approval' => true,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_reopens_review',
            'email' => 'guest@example.test',
        ]);
        Rsvp::factory()->forGuest($guest)->accepted(1)->create([
            'host_approval_status' => RsvpApprovalStatus::Rejected,
            'host_reviewed_at' => now(),
            'host_rejection_note' => 'old note',
        ]);

        // A host's rejection is final (plans/rsvp-status-changes.md Phase 3, decision 2): declining is allowed, coming
        // back is not, no new review is queued, and the host's note is kept.
        $this->post(route('rsvp.token.store', ['token' => 'tok_reopens_review']), $this->rsvpPayload(RsvpStatus::Declined, 0));
        $this->post(route('rsvp.token.store', ['token' => 'tok_reopens_review']), $this->rsvpPayload(RsvpStatus::Accepted, 1))
            ->assertSessionHasErrors('status');

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpStatus::Declined, $rsvp->status);
        $this->assertSame(RsvpApprovalStatus::Rejected, $rsvp->host_approval_status);
        $this->assertNotNull($rsvp->host_reviewed_at);
        $this->assertSame('old note', $rsvp->host_rejection_note);
    }

    public function test_toggling_requirement_on_does_not_retroactively_pend_existing_acceptances(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'require_rsvp_approval' => false,
        ]);
        $guest = Guest::factory()->for($event)->create();
        $rsvp = Rsvp::factory()->forGuest($guest)->accepted()->create()->fresh();

        $this->assertSame(RsvpApprovalStatus::NotRequired, $rsvp->host_approval_status);

        $event->update(['require_rsvp_approval' => true]);

        $this->assertSame(RsvpApprovalStatus::NotRequired, $rsvp->fresh()->host_approval_status);
    }
}
