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
use App\Notifications\RsvpExtraSeatPendingNotification;
use App\Notifications\RsvpRejectedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/rsvp-status-changes.md Phase 1: the transition matrix. What happens when a guest moves between answers,
 * with and without host approval, against the seat limit, and across the deadline and the start.
 *
 * Tests marked `pinned_` record behaviour that a later phase of the plan changes (the host override, Phase 5).
 */
class RsvpStatusTransitionsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->host = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-05 18:00:00',
            'allow_plus_one' => true,
            'guest_limit' => null,
            'require_rsvp_approval' => false,
        ]);

        $this->at('2026-10-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $venueTime): void
    {
        Carbon::setTestNow(Carbon::parse($venueTime, config('events.timezone'))->utc());
    }

    private function guest(?RsvpStatus $status, int $seats = 1, RsvpApprovalStatus $approval = RsvpApprovalStatus::NotRequired, array $rsvp = []): Guest
    {
        $guest = Guest::factory()->for($this->event)->create(['plus_one_allowed' => true]);

        if ($status !== null) {
            Rsvp::query()->create(array_merge([
                'event_id' => $this->event->id,
                'guest_id' => $guest->id,
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? $seats : 0,
                'host_approval_status' => $approval,
            ], $rsvp));
        }

        return $guest;
    }

    private function answer(Guest $guest, string $status, int $seats = 1)
    {
        return $this->post(route('rsvp.token.store', $guest->invitation_token), [
            'status' => $status,
            'attendee_count' => $status === 'accepted' ? $seats : 0,
        ]);
    }

    // ---------------------------------------------------------------- the transitions, approval off

    /**
     * @return array<string, array{0: RsvpStatus, 1: int, 2: string, 3: int, 4: RsvpStatus, 5: int}>
     */
    public static function transitions(): array
    {
        return [
            'accepted(2) to declined' => [RsvpStatus::Accepted, 2, 'declined', 0, RsvpStatus::Declined, 0],
            'declined to accepted' => [RsvpStatus::Declined, 0, 'accepted', 1, RsvpStatus::Accepted, 1],
            'maybe to accepted' => [RsvpStatus::Maybe, 0, 'accepted', 2, RsvpStatus::Accepted, 2],
            'accepted to maybe' => [RsvpStatus::Accepted, 1, 'maybe', 0, RsvpStatus::Maybe, 0],
            'accepted(2) to accepted(1)' => [RsvpStatus::Accepted, 2, 'accepted', 1, RsvpStatus::Accepted, 1],
            'declined to maybe' => [RsvpStatus::Declined, 0, 'maybe', 0, RsvpStatus::Maybe, 0],
        ];
    }

    #[DataProvider('transitions')]
    public function test_a_guest_can_change_their_answer_before_the_deadline(RsvpStatus $from, int $fromSeats, string $to, int $toSeats, RsvpStatus $expected, int $expectedSeats): void
    {
        $guest = $this->guest($from, $fromSeats);

        $this->answer($guest, $to, $toSeats)
            ->assertRedirect(route('rsvp.token.thanks', $guest->invitation_token))
            ->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame($expected, $rsvp->status);
        $this->assertSame($expectedSeats, $rsvp->attendee_count);
        $this->assertSame(RsvpApprovalStatus::NotRequired, $rsvp->host_approval_status);
        $this->assertSame($expectedSeats, $rsvp->heldSeats());

        // The guest is told what was saved (a "Not attending" confirmation included), and so is the host.
        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 1);
        Notification::assertSentToTimes($this->host, NewRsvpReceivedNotification::class, 1);
    }

    public function test_the_entry_pass_follows_the_answer(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, 1);
        $this->assertTrue($guest->hasEntryPassFor($guest->rsvp, $this->event));

        $this->answer($guest, 'declined')->assertSessionHasNoErrors();
        $this->assertFalse($guest->fresh()->hasEntryPassFor($guest->fresh()->rsvp, $this->event));
        $this->get(route('rsvp.token.pass', $guest->invitation_token))->assertRedirect(route('rsvp.token.show', $guest->invitation_token));

        $this->answer($guest, 'accepted')->assertSessionHasNoErrors();
        $this->assertTrue($guest->fresh()->hasEntryPassFor($guest->fresh()->rsvp, $this->event));
        $this->get(route('rsvp.token.pass', $guest->invitation_token))->assertOk();
    }

    public function test_the_same_answer_again_changes_nothing_and_tells_nobody(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);

        $this->answer($guest, 'declined')->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- with host approval

    public function test_with_approval_on_a_guest_coming_back_to_accepted_goes_to_pending_and_the_host_is_asked(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);

        foreach ([RsvpStatus::Declined, RsvpStatus::Maybe] as $from) {
            $guest = $this->guest($from);

            $this->answer($guest, 'accepted')->assertSessionHasNoErrors();

            $rsvp = $guest->fresh()->rsvp;
            $this->assertSame(RsvpApprovalStatus::Pending, $rsvp->host_approval_status, $from->value.' to accepted');
            $this->assertFalse($guest->fresh()->hasEntryPassFor($rsvp, $this->event), 'no pass while pending');
        }

        Notification::assertSentToTimes($this->host, RsvpAwaitingApprovalNotification::class, 2);
        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 0);
    }

    public function test_an_approved_guest_who_only_changes_seats_stays_approved(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 2, RsvpApprovalStatus::Approved);

        $this->answer($guest, 'accepted', 1)->assertSessionHasNoErrors();

        $this->assertSame(RsvpApprovalStatus::Approved, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_declining_clears_an_approval(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Approved);

        $this->answer($guest, 'declined')->assertSessionHasNoErrors();

        $this->assertSame(RsvpApprovalStatus::NotRequired, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_an_approved_guest_who_toggles_through_maybe_keeps_their_approval_and_pass(): void
    {
        // plans/rsvp-status-changes.md S4 (Phase 3): approval follows seats, not answers.
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Approved, ['approved_seats' => 1]);

        $this->answer($guest, 'maybe')->assertSessionHasNoErrors();
        $this->assertSame(1, $guest->fresh()->rsvp->approved_seats, 'the approval survives a decline or maybe');

        Notification::fake();
        $this->answer($guest, 'accepted')->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status);
        $this->assertTrue($guest->fresh()->hasEntryPassFor($rsvp, $this->event));
        Notification::assertNotSentTo($this->host, RsvpAwaitingApprovalNotification::class);
    }

    public function test_a_row_approved_before_approved_seats_existed_is_covered_by_the_seats_it_holds(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 2, RsvpApprovalStatus::Approved);
        $this->assertNull($guest->rsvp->approved_seats);

        $this->answer($guest, 'declined')->assertSessionHasNoErrors();
        $this->assertSame(2, $guest->fresh()->rsvp->approved_seats, 'the legacy approval is written down on the way out');

        $this->answer($guest, 'accepted', 2)->assertSessionHasNoErrors();
        $this->assertSame(RsvpApprovalStatus::Approved, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_coming_back_for_more_seats_than_were_approved_reviews_only_the_extra_seat(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Declined, 0, RsvpApprovalStatus::NotRequired, ['approved_seats' => 1]);

        $this->answer($guest, 'accepted', 2)->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Pending, $rsvp->host_approval_status);
        $this->assertSame(2, $rsvp->attendee_count);
        $this->assertSame(1, $rsvp->approved_seats);
    }

    public function test_an_approved_guest_who_adds_a_plus_one_keeps_the_pass_for_the_approved_seat_and_the_host_is_asked_about_the_extra(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Approved, ['approved_seats' => 1]);

        $this->answer($guest, 'accepted', 2)->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Pending, $rsvp->host_approval_status);
        $this->assertSame(1, $rsvp->approved_seats);
        $this->assertSame(1, $rsvp->passSeats(), 'the pass admits only the approved seat');
        $this->assertTrue($guest->fresh()->hasEntryPassFor($rsvp, $this->event), 'the pass is not taken back');

        Notification::assertSentToTimes($this->host, RsvpAwaitingApprovalNotification::class, 1);
        Notification::assertSentOnDemandTimes(RsvpExtraSeatPendingNotification::class, 1);
        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 0);

        $this->get(route('rsvp.token.show', $guest->invitation_token))->assertOk()->assertSee('Your extra seat is waiting for the host');
    }

    public function test_the_host_approving_the_extra_seat_raises_the_approved_seats(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 2, RsvpApprovalStatus::Pending, ['approved_seats' => 1]);

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.approve', ['event' => $this->event, 'guest' => $guest]))
            ->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status);
        $this->assertSame(2, $rsvp->approved_seats);
        $this->assertSame(2, $rsvp->passSeats());
    }

    public function test_the_host_declining_the_extra_seat_leaves_the_rsvp_approved_for_the_original_seats(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 2, RsvpApprovalStatus::Pending, ['approved_seats' => 1]);

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.reject', ['event' => $this->event, 'guest' => $guest]), ['host_rejection_note' => 'No room for one more.'])
            ->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status, 'the guest is not thrown out');
        $this->assertSame(1, $rsvp->attendee_count);
        $this->assertTrue($guest->fresh()->hasEntryPassFor($rsvp, $this->event));
        Notification::assertSentOnDemand(RsvpRejectedNotification::class, fn ($n) => $n->extraSeatOnly === true);
    }

    public function test_the_host_declining_a_first_request_is_final_for_that_guest(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Pending);

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.reject', ['event' => $this->event, 'guest' => $guest]), ['host_rejection_note' => 'The list is full.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(RsvpApprovalStatus::Rejected, $guest->fresh()->rsvp->host_approval_status);
        Notification::assertSentOnDemand(RsvpRejectedNotification::class, fn ($n) => $n->extraSeatOnly === false);
    }

    public function test_a_rejected_guest_who_declines_and_re_accepts_stays_rejected_and_keeps_the_note(): void
    {
        // plans/rsvp-status-changes.md S5 (Phase 3, decision 2): a host's rejection is final.
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Rejected, [
            'host_rejection_note' => 'The list is full.',
            'host_reviewed_at' => now(),
            'host_reviewed_by' => $this->host->id,
        ]);

        // Declining is allowed, and the rejection is kept through it.
        $this->answer($guest, 'declined')->assertSessionHasNoErrors();
        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpStatus::Declined, $rsvp->status);
        $this->assertSame(RsvpApprovalStatus::Rejected, $rsvp->host_approval_status);

        Notification::fake();

        // Coming back is not: nothing is queued, and the guest is told why.
        $this->answer($guest, 'accepted')->assertSessionHasErrors(['status' => 'The host has already declined your request to attend. If you think this is a mistake, please contact the host.']);

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpStatus::Declined, $rsvp->status);
        $this->assertSame(RsvpApprovalStatus::Rejected, $rsvp->host_approval_status);
        $this->assertSame('The list is full.', $rsvp->host_rejection_note);
        Notification::assertNothingSent();
    }

    public function test_a_rejected_guest_cannot_change_seats_or_message_either(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Rejected, ['host_rejection_note' => 'The list is full.']);

        $this->answer($guest, 'accepted', 2)->assertSessionHasErrors('status');
        $this->assertSame(1, $guest->fresh()->rsvp->attendee_count);
        $this->assertSame(RsvpApprovalStatus::Rejected, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_a_rejected_guest_who_declined_still_does_not_see_a_not_confirmed_panel(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Declined, 0, RsvpApprovalStatus::Rejected, ['host_rejection_note' => 'The list is full.']);

        $this->get(route('rsvp.token.show', $guest->invitation_token))->assertOk()->assertDontSee('The host was not able to confirm your RSVP');
    }

    public function test_a_rejected_guest_who_just_resubmits_accepted_stays_rejected(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Rejected, ['host_rejection_note' => 'The list is full.']);

        $this->answer($guest, 'accepted')->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Rejected, $rsvp->host_approval_status);
        $this->assertSame('The list is full.', $rsvp->host_rejection_note);
    }

    // ---------------------------------------------------------------- seat limit

    public function test_going_back_to_accepted_is_checked_against_the_guest_limit_and_a_decline_frees_the_seat(): void
    {
        $this->event->update(['guest_limit' => 2]);

        $holder = $this->guest(RsvpStatus::Accepted, 2);
        $comeback = $this->guest(RsvpStatus::Declined);
        $maybe = $this->guest(RsvpStatus::Maybe);

        $this->answer($comeback, 'accepted')->assertSessionHasErrors('status');
        $this->answer($maybe, 'accepted')->assertSessionHasErrors('status');
        $this->assertSame(RsvpStatus::Declined, $comeback->fresh()->rsvp->status);
        $this->assertSame(RsvpStatus::Maybe, $maybe->fresh()->rsvp->status);

        // The holder declines; the seat is free for the next person to claim it.
        $this->answer($holder, 'declined')->assertSessionHasNoErrors();
        $this->answer($comeback, 'accepted')->assertSessionHasNoErrors();
        $this->assertSame(RsvpStatus::Accepted, $comeback->fresh()->rsvp->status);

        // ...and now the other one is the one turned away.
        $this->answer($maybe, 'accepted', 2)->assertSessionHasErrors('status');
    }

    // ---------------------------------------------------------------- after the deadline

    public function test_after_the_deadline_a_guest_can_cancel_or_reduce_but_never_come_back_or_add(): void
    {
        $this->at('2026-10-06 09:00:00');

        $cancelling = $this->guest(RsvpStatus::Accepted, 2);
        $softening = $this->guest(RsvpStatus::Accepted, 1);
        $declined = $this->guest(RsvpStatus::Declined);
        $maybe = $this->guest(RsvpStatus::Maybe);
        $adding = $this->guest(RsvpStatus::Accepted, 1);

        $this->answer($cancelling, 'declined')->assertSessionHasNoErrors();
        $this->answer($softening, 'maybe')->assertSessionHasNoErrors();
        $this->assertSame(RsvpStatus::Declined, $cancelling->fresh()->rsvp->status);
        $this->assertSame(RsvpStatus::Maybe, $softening->fresh()->rsvp->status);

        foreach ([[$declined, 'accepted', 1], [$maybe, 'accepted', 1], [$adding, 'accepted', 2]] as [$guest, $status, $seats]) {
            $this->answer($guest, $status, $seats)
                ->assertRedirect(route('rsvp.token.show', $guest->invitation_token))
                ->assertSessionHas('rsvp_closed');
        }

        $this->assertSame(RsvpStatus::Declined, $declined->fresh()->rsvp->status);
        $this->assertSame(RsvpStatus::Maybe, $maybe->fresh()->rsvp->status);
        $this->assertSame(1, $adding->fresh()->rsvp->attendee_count);
    }

    public function test_after_the_deadline_the_open_form_never_changes_an_answer_because_an_email_is_not_a_secret(): void
    {
        $this->at('2026-10-06 09:00:00');
        $guest = $this->guest(RsvpStatus::Accepted, 1);

        $this->post(route('rsvp.open.store', ['slug' => $this->event->slug]), [
            'name' => $guest->name,
            'email' => $guest->email,
            'phone' => '0970000000',
            'status' => 'declined',
            'attendee_count' => 0,
        ])->assertSessionHas('rsvp_closed');

        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
    }

    // ---------------------------------------------------------------- after the event starts

    public function test_once_the_event_has_started_no_guest_change_is_accepted_not_even_a_decline(): void
    {
        $this->at('2026-11-20 15:30:00');

        foreach ([[RsvpStatus::Accepted, 'declined'], [RsvpStatus::Accepted, 'maybe'], [RsvpStatus::Declined, 'accepted'], [RsvpStatus::Maybe, 'accepted']] as [$from, $to]) {
            $guest = $this->guest($from);

            $this->answer($guest, $to)->assertSessionHas('rsvp_closed');

            $this->assertSame($from, $guest->fresh()->rsvp->status, "$from->value to $to after the start");
        }

        // The page agrees: no "cancel my RSVP" on the closed page once it has started.
        $this->get(route('rsvp.token.show', $this->guest(RsvpStatus::Accepted)->invitation_token))->assertOk()->assertDontSee('Cancel my RSVP');
    }

    public function test_the_next_day_it_is_still_refused(): void
    {
        $this->at('2026-11-21 10:00:00');
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->answer($guest, 'declined')->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
    }

    // ---------------------------------------------------------------- the host

    public function test_pinned_the_host_has_no_way_to_set_a_guests_answer_only_to_approve_or_reject_a_pending_one(): void
    {
        // plans/rsvp-status-changes.md S6. Phase 5 adds a host override.
        $guest = $this->guest(RsvpStatus::Accepted, 1);

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.approve', ['event' => $this->event, 'guest' => $guest]))
            ->assertSessionHasErrors('rsvp_approval');

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.reject', ['event' => $this->event, 'guest' => $guest]), ['host_rejection_note' => 'No'])
            ->assertSessionHasErrors('rsvp_approval');

        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
        $this->assertSame(RsvpApprovalStatus::NotRequired, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_the_host_approving_a_pending_guest_sends_the_confirmation_and_the_pass(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, RsvpApprovalStatus::Pending);

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.approve', ['event' => $this->event, 'guest' => $guest]))
            ->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status);
        $this->assertTrue($guest->fresh()->hasEntryPassFor($rsvp, $this->event));
        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 1);
    }
}
