<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Exceptions\RsvpClosedException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\RsvpSubmissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-deadline-fixes.md, Phase 2: a late submit explains itself instead of a bare 403, the service
 * enforces the deadline under its lock, and a guest who already answered may still cancel or reduce
 * (never increase) until the event starts. Times are on the venue clock (Africa/Lusaka).
 */
class RsvpClosedHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->event = Event::factory()->published()->create([
            'user_id' => User::factory(),
            'is_public' => true,
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-05 18:00:00',
            'allow_plus_one' => true,
        ]);
    }

    private function at(string $venueTime): void
    {
        Carbon::setTestNow(Carbon::parse($venueTime, config('events.timezone'))->utc());
    }

    private function afterDeadline(): void
    {
        $this->at('2026-10-06 09:00:00');
    }

    private function guest(?RsvpStatus $status = null, int $count = 1, bool $plusOne = false): Guest
    {
        $guest = Guest::factory()->create(['event_id' => $this->event->id, 'plus_one_allowed' => $plusOne]);

        if ($status !== null) {
            Rsvp::query()->create([
                'event_id' => $this->event->id,
                'guest_id' => $guest->id,
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? $count : 0,
            ]);
        }

        return $guest;
    }

    private function submit(Guest $guest, string $status, int $count)
    {
        return $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => $status, 'attendee_count' => $count]);
    }

    // ── G4: a late submit explains itself ────────────────────────────────────

    public function test_a_late_web_submit_from_a_guest_with_no_answer_goes_to_the_closed_page_with_a_reason(): void
    {
        $guest = $this->guest();
        $this->afterDeadline();

        $this->submit($guest, 'accepted', 1)
            ->assertRedirect(route('rsvp.token.show', $guest->invitation_token))
            ->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'deadline has passed'));

        $this->assertNull($guest->fresh()->rsvp);

        $this->followingRedirects()
            ->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertOk()
            ->assertSee('deadline has passed')
            ->assertSee('Deadline was Monday, October 5, 2026 at 6:00 PM CAT')
            ->assertDontSee('Cancel my RSVP');
    }

    public function test_a_late_api_submit_is_a_403_with_a_stable_code_and_message(): void
    {
        $guest = $this->guest();
        $this->afterDeadline();

        $this->postJson(route('api.v1.rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'rsvp_closed')
            ->assertJsonPath('can_reduce', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'deadline has passed'));
    }

    public function test_the_open_rsvp_form_gets_the_same_friendly_refusal(): void
    {
        $this->afterDeadline();

        $this->post(route('rsvp.open.store', $this->event->slug), ['name' => 'Late Lee', 'email' => 'lee@example.com', 'status' => 'accepted', 'attendee_count' => 1])
            ->assertRedirect(route('rsvp.open.show', $this->event->slug))
            ->assertSessionHas('rsvp_closed');
    }

    // ── G5: the service enforces it under the lock ───────────────────────────

    public function test_the_service_refuses_after_the_deadline_even_when_the_request_check_was_passed(): void
    {
        $guest = $this->guest();
        $this->afterDeadline();

        $this->expectException(RsvpClosedException::class);

        app(RsvpSubmissionService::class)->submit($this->event->fresh(), $guest, ['status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
    }

    public function test_the_service_can_be_told_not_to_enforce_for_a_host_initiated_path(): void
    {
        $guest = $this->guest();
        $this->afterDeadline();

        $rsvp = app(RsvpSubmissionService::class)->submit($this->event->fresh(), $guest, ['status' => RsvpStatus::Accepted, 'attendee_count' => 1], enforceDeadline: false);

        $this->assertSame(RsvpStatus::Accepted, $rsvp->status);
    }

    public function test_a_deadline_that_passes_between_the_page_and_the_submit_is_caught(): void
    {
        $guest = $this->guest();

        $this->at('2026-10-05 17:00:00');
        $this->get(route('rsvp.token.show', $guest->invitation_token))->assertOk()->assertSee('class="rsvp-form"', false);

        // The form was open at 17:00; the submit arrives after the grace has also run out.
        $this->at('2026-10-05 18:05:00');
        $this->submit($guest, 'accepted', 1)->assertSessionHas('rsvp_closed');
        $this->assertNull($guest->fresh()->rsvp);
    }

    // ── G3: cancel or reduce after the deadline, never increase ──────────────

    public function test_a_guest_who_accepted_can_cancel_after_the_deadline(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $this->afterDeadline();

        $this->submit($guest, 'declined', 0)->assertRedirect()->assertSessionMissing('rsvp_closed');

        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);
    }

    public function test_a_plus_one_can_be_dropped_but_not_added_after_the_deadline(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, count: 2, plusOne: true);
        $this->afterDeadline();

        $this->submit($guest, 'accepted', 1)->assertSessionMissing('rsvp_closed');
        $this->assertSame(1, $guest->fresh()->rsvp->attendee_count);

        $this->submit($guest, 'accepted', 2)
            ->assertRedirect(route('rsvp.token.show', $guest->invitation_token))
            ->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'cancel your RSVP or reduce'));
        $this->assertSame(1, $guest->fresh()->rsvp->attendee_count, 'no increase after the deadline');
    }

    public function test_an_accepted_guest_can_switch_to_maybe_after_the_deadline(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $this->afterDeadline();

        $this->submit($guest, 'maybe', 0)->assertSessionMissing('rsvp_closed');

        $this->assertSame(RsvpStatus::Maybe, $guest->fresh()->rsvp->status);
    }

    public function test_a_guest_who_declined_cannot_come_back_after_the_deadline(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);
        $this->afterDeadline();

        $this->submit($guest, 'accepted', 1)->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);

        $this->submit($guest, 'maybe', 0)->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);
    }

    public function test_a_maybe_cannot_become_an_accept_after_the_deadline(): void
    {
        $guest = $this->guest(RsvpStatus::Maybe);
        $this->afterDeadline();

        $this->submit($guest, 'accepted', 1)->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Maybe, $guest->fresh()->rsvp->status);
    }

    public function test_changes_stop_when_the_event_starts(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->at('2026-11-20 14:59:00');
        $this->submit($guest, 'declined', 0)->assertSessionMissing('rsvp_closed');

        $other = $this->guest(RsvpStatus::Accepted);
        $this->at('2026-11-20 15:01:00');
        $this->submit($other, 'declined', 0)->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Accepted, $other->fresh()->rsvp->status);
    }

    public function test_a_cancelled_event_refuses_every_change(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $this->event->forceFill(['cancelled_at' => now()])->save();
        $this->afterDeadline();

        $this->submit($guest, 'declined', 0)->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
    }

    public function test_the_open_form_never_reduces_because_an_email_alone_is_not_a_secret(): void
    {
        $guest = Guest::factory()->create(['event_id' => $this->event->id, 'email' => 'sam@example.com']);
        Rsvp::query()->create(['event_id' => $this->event->id, 'guest_id' => $guest->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
        $this->afterDeadline();

        $this->post(route('rsvp.open.store', $this->event->slug), ['name' => $guest->name, 'email' => 'sam@example.com', 'status' => 'declined', 'attendee_count' => 0])
            ->assertSessionHas('rsvp_closed');

        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
    }

    public function test_the_service_does_not_reduce_unless_the_caller_vouches_for_the_guest(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $this->afterDeadline();

        $this->expectException(RsvpClosedException::class);

        app(RsvpSubmissionService::class)->submit($this->event->fresh(), $guest, ['status' => RsvpStatus::Declined, 'attendee_count' => 0]);
    }

    // ── The closed page and the API flag ─────────────────────────────────────

    public function test_the_closed_page_offers_cancel_and_reduce_only_to_a_guest_who_can_use_them(): void
    {
        $solo = $this->guest(RsvpStatus::Accepted);
        $withPlusOne = $this->guest(RsvpStatus::Accepted, count: 2, plusOne: true);
        $declined = $this->guest(RsvpStatus::Declined);
        $unanswered = $this->guest();
        $this->afterDeadline();

        $this->get(route('rsvp.token.show', $solo->invitation_token))
            ->assertOk()->assertSee('Cancel my RSVP')->assertDontSee('Only me, not my plus-one');
        $this->get(route('rsvp.token.show', $withPlusOne->invitation_token))
            ->assertOk()->assertSee('Cancel my RSVP')->assertSee('Only me, not my plus-one');
        $this->get(route('rsvp.token.show', $declined->invitation_token))->assertOk()->assertDontSee('Cancel my RSVP');
        $this->get(route('rsvp.token.show', $unanswered->invitation_token))->assertOk()->assertDontSee('Cancel my RSVP');

        $this->at('2026-11-20 16:00:00');
        $this->get(route('rsvp.token.show', $solo->invitation_token))->assertOk()->assertDontSee('Cancel my RSVP');
    }

    public function test_the_api_says_whether_a_guest_can_still_reduce(): void
    {
        $answered = $this->guest(RsvpStatus::Accepted);
        $unanswered = $this->guest();
        $this->afterDeadline();

        $this->getJson(route('api.v1.rsvp.token.show', $answered->invitation_token))->assertOk()->assertJsonPath('can_reduce', true);
        $this->getJson(route('api.v1.rsvp.token.show', $unanswered->invitation_token))->assertOk()->assertJsonPath('can_reduce', false);

        $this->postJson(route('api.v1.rsvp.token.store', $answered->invitation_token), ['status' => 'declined', 'attendee_count' => 0])
            ->assertOk()
            ->assertJsonPath('rsvp.status', 'declined')
            ->assertJsonPath('can_reduce', false);
    }
}
