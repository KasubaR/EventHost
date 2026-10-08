<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use App\Models\User;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\RsvpConfirmationNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-status-changes.md Phase 5: the host records a guest's answer for them, at any time. The seat limit still
 * applies unless the host ticks "allow over the guest limit"; the guest is told only if the host ticks "tell the guest".
 */
class HostRsvpOverrideTest extends TestCase
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
            'allow_plus_one' => false,
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

    private function guest(?RsvpStatus $status = null, int $seats = 1, array $rsvp = [], array $guest = []): Guest
    {
        $g = Guest::factory()->for($this->event)->create($guest);

        if ($status !== null) {
            Rsvp::query()->create(array_merge([
                'event_id' => $this->event->id,
                'guest_id' => $g->id,
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? $seats : 0,
                'host_approval_status' => RsvpApprovalStatus::NotRequired,
            ], $rsvp));
        }

        return $g;
    }

    private function set(Guest $guest, array $data, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->host)->patch(route('events.guests.rsvp.set', ['event' => $this->event, 'guest' => $guest->id]), $data);
    }

    // ---------------------------------------------------------------- it works whenever

    public function test_the_host_can_record_a_response_for_a_guest_who_never_answered(): void
    {
        $guest = $this->guest();

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 1])->assertSessionHasNoErrors()->assertSessionHas('status', 'guest-rsvp-set');

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpStatus::Accepted, $rsvp->status);
        $this->assertSame(1, $rsvp->attendee_count);
    }

    public function test_it_works_after_the_deadline_and_after_the_event_has_started_and_the_next_day(): void
    {
        foreach (['2026-10-06 09:00:00', '2026-11-20 16:00:00', '2026-11-21 10:00:00'] as $when) {
            $this->at($when);
            $guest = $this->guest(RsvpStatus::Accepted);

            $this->set($guest, ['status' => 'declined'])->assertSessionHasNoErrors();

            $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status, $when);
        }
    }

    public function test_it_works_for_a_guest_who_is_already_checked_in(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $guest->forceFill(['checked_in_at' => now()])->save();

        $this->set($guest, ['status' => 'declined'])->assertSessionHasNoErrors();

        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);
    }

    public function test_it_works_for_an_event_that_is_cancelled_or_unpublished(): void
    {
        $this->event->forceFill(['cancelled_at' => now()])->save();
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->set($guest, ['status' => 'maybe'])->assertSessionHasNoErrors();

        $this->assertSame(RsvpStatus::Maybe, $guest->fresh()->rsvp->status);
    }

    public function test_a_guests_message_is_kept(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, 1, ['message' => 'Vegetarian, please']);

        $this->set($guest, ['status' => 'maybe'])->assertSessionHasNoErrors();

        $this->assertSame('Vegetarian, please', $guest->fresh()->rsvp->message);
    }

    public function test_the_host_can_add_a_plus_one_even_when_the_guests_own_flag_is_off(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, 1, [], ['plus_one_allowed' => false]);

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 2])->assertSessionHasNoErrors();

        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_more_than_two_seats_is_refused(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 3])->assertSessionHasErrors('attendee_count');
        $this->set($guest, ['status' => 'garbage'])->assertSessionHasErrors('status');
    }

    // ---------------------------------------------------------------- approval

    public function test_with_approval_on_a_host_set_acceptance_is_the_approval_and_queues_no_review(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Declined);

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 2])->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status);
        $this->assertSame(2, $rsvp->approved_seats);
        $this->assertSame($this->host->id, $rsvp->host_reviewed_by);
        $this->assertTrue($guest->fresh()->hasEntryPassFor($rsvp, $this->event));
    }

    public function test_the_host_can_lift_a_rejection_by_setting_the_guest_to_attending(): void
    {
        $this->event->update(['require_rsvp_approval' => true]);
        $guest = $this->guest(RsvpStatus::Accepted, 1, ['host_approval_status' => RsvpApprovalStatus::Rejected, 'host_rejection_note' => 'Full.']);

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 1])->assertSessionHasNoErrors();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpApprovalStatus::Approved, $rsvp->host_approval_status);
        $this->assertNull($rsvp->host_rejection_note);
    }

    // ---------------------------------------------------------------- the guest limit

    public function test_the_guest_limit_applies_unless_the_host_ticks_the_box(): void
    {
        $this->event->update(['guest_limit' => 1]);
        $this->guest(RsvpStatus::Accepted, 1);
        $newcomer = $this->guest();

        $this->set($newcomer, ['status' => 'accepted', 'attendee_count' => 1])->assertSessionHasErrors('status', errorBag: 'rsvpSet');
        $this->assertNull($newcomer->fresh()->rsvp, 'nothing saved without the tick');
        $this->assertSame(0, RsvpChange::query()->where('guest_id', $newcomer->id)->count());

        $this->set($newcomer, ['status' => 'accepted', 'attendee_count' => 1, 'allow_over_limit' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(RsvpStatus::Accepted, $newcomer->fresh()->rsvp->status);
        $change = RsvpChange::query()->where('guest_id', $newcomer->id)->sole();
        $this->assertTrue($change->over_limit);
        $this->assertStringContainsString('over the guest limit', $change->describe());
    }

    public function test_ticking_the_box_when_the_limit_is_not_hit_is_not_recorded_as_over(): void
    {
        $this->event->update(['guest_limit' => 10]);
        $guest = $this->guest();

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 1, 'allow_over_limit' => '1'])->assertSessionHasNoErrors();

        $this->assertFalse(RsvpChange::query()->where('guest_id', $guest->id)->sole()->over_limit);
    }

    public function test_a_groups_seat_pool_needs_the_same_tick(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create();
        $group->enableLink(1);
        $group = $group->fresh();

        $first = $this->guest(RsvpStatus::Accepted, 1, [], ['guest_group_id' => $group->id]);
        $second = $this->guest(null, 1, [], ['guest_group_id' => $group->id]);

        $this->set($second, ['status' => 'accepted', 'attendee_count' => 1])->assertSessionHasErrors('status', errorBag: 'rsvpSet');
        $this->set($second, ['status' => 'accepted', 'attendee_count' => 1, 'allow_over_limit' => '1'])->assertSessionHasNoErrors();

        $this->assertTrue(RsvpChange::query()->where('guest_id', $second->id)->sole()->over_limit);
        $this->assertSame(RsvpStatus::Accepted, $first->fresh()->rsvp->status);
    }

    public function test_the_limit_box_means_nothing_to_a_guest_submitting_for_themselves(): void
    {
        // Only the host path honours it; a guest's own request carrying the field is just another request.
        $this->event->update(['guest_limit' => 1]);
        $this->guest(RsvpStatus::Accepted, 1);
        $newcomer = $this->guest();

        $this->post(route('rsvp.token.store', $newcomer->invitation_token), ['status' => 'accepted', 'attendee_count' => 1, 'allow_over_limit' => '1'])
            ->assertSessionHasErrors('status');

        $this->assertNull($newcomer->fresh()->rsvp);
    }

    // ---------------------------------------------------------------- who is told

    public function test_nobody_is_emailed_by_default_not_even_the_host(): void
    {
        $guest = $this->guest();

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 1])->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_the_guest_is_told_only_when_the_host_ticks_tell_the_guest_and_the_host_is_never_alerted(): void
    {
        $guest = $this->guest();

        $this->set($guest, ['status' => 'accepted', 'attendee_count' => 1, 'notify_guest' => '1'])->assertSessionHasNoErrors();

        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 1);
        Notification::assertNotSentTo($this->host, NewRsvpReceivedNotification::class);
    }

    public function test_setting_the_answer_that_is_already_there_changes_and_sends_nothing(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);

        $this->set($guest, ['status' => 'declined', 'notify_guest' => '1'])->assertSessionHas('status', 'guest-rsvp-set-unchanged');

        Notification::assertNothingSent();
        $this->assertSame(0, RsvpChange::query()->where('guest_id', $guest->id)->count());
    }

    // ---------------------------------------------------------------- history

    public function test_the_change_is_in_the_history_as_the_host_with_their_name(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, 1);

        $this->set($guest, ['status' => 'declined'])->assertSessionHasNoErrors();

        $change = RsvpChange::query()->where('guest_id', $guest->id)->sole();
        $this->assertSame(RsvpChange::CHANNEL_HOST, $change->channel);
        $this->assertSame($this->host->id, $change->actor_user_id);

        $this->actingAs($this->host)->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertOk()
            ->assertSee('Host ('.$this->host->name.')', false)
            ->assertSee("Set this guest's response", false);
    }

    // ---------------------------------------------------------------- who may

    public function test_another_host_cannot_set_a_guests_response(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->set($guest, ['status' => 'declined'], User::factory()->create())->assertForbidden();

        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
    }

    public function test_a_guest_of_another_event_is_a_404(): void
    {
        $other = Event::factory()->for($this->host)->published()->create();
        $stranger = Guest::factory()->for($other)->create();

        $this->set($stranger, ['status' => 'accepted', 'attendee_count' => 1])->assertNotFound();
    }

    public function test_it_is_not_available_for_a_ticketed_event(): void
    {
        $ticketed = Event::factory()->for($this->host)->ticketed()->published()->create();
        $guest = Guest::factory()->for($ticketed)->create();

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.set', ['event' => $ticketed, 'guest' => $guest->id]), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------- the API twin

    public function test_the_api_sets_a_response_with_the_same_rules(): void
    {
        $this->event->update(['guest_limit' => 1]);
        $this->guest(RsvpStatus::Accepted, 1);
        $newcomer = $this->guest();
        $headers = ['Authorization' => 'Bearer '.$this->host->createToken('t')->plainTextToken];
        $url = route('api.v1.host.events.guests.rsvp.set', ['event' => $this->event, 'guest' => $newcomer->id]);

        $this->withHeaders($headers)->patchJson($url, ['status' => 'accepted', 'attendee_count' => 1])->assertStatus(422);
        $this->assertNull($newcomer->fresh()->rsvp);

        $this->withHeaders($headers)->patchJson($url, ['status' => 'accepted', 'attendee_count' => 1, 'allow_over_limit' => true])->assertOk();
        $this->assertSame(RsvpStatus::Accepted, $newcomer->fresh()->rsvp->status);
        $this->assertTrue(RsvpChange::query()->where('guest_id', $newcomer->id)->sole()->over_limit);
    }
}
