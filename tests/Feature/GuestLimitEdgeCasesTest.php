<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuestLimitEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithLimit(?int $limit, bool $plusOne = false): Event
    {
        return Event::factory()->for(User::factory()->create())->published()->create([
            'is_public' => true,
            'guest_limit' => $limit,
            'allow_plus_one' => $plusOne,
            'rsvp_deadline' => null,
        ]);
    }

    private function guest(Event $event, string $token, bool $plusOne = false): Guest
    {
        return Guest::factory()->for($event)->create([
            'invitation_token' => $token,
            'plus_one_allowed' => $plusOne,
        ]);
    }

    private function submit(string $token, RsvpStatus $status, int $count = 1)
    {
        return $this->post(route('rsvp.token.store', ['token' => $token]), [
            'status' => $status->value,
            'attendee_count' => $count,
            'message' => null,
        ]);
    }

    public function test_limit_of_one_accepts_one_and_refuses_the_next(): void
    {
        Notification::fake();
        $event = $this->eventWithLimit(1);
        $this->guest($event, 'tok_a');
        $this->guest($event, 'tok_b');

        $this->submit('tok_a', RsvpStatus::Accepted)->assertSessionHasNoErrors();
        $this->submit('tok_b', RsvpStatus::Accepted)->assertSessionHasErrors('status');
    }

    public function test_plus_one_that_overshoots_names_the_seats_left(): void
    {
        Notification::fake();
        $event = $this->eventWithLimit(3, plusOne: true);
        $this->guest($event, 'tok_a');
        $this->guest($event, 'tok_b', plusOne: true);
        Rsvp::factory()->forGuest(Guest::where('invitation_token', 'tok_a')->first())->accepted(2)->create();

        $this->submit('tok_b', RsvpStatus::Accepted, 2)
            ->assertSessionHasErrors(['status' => 'Only 1 seat is left for confirmed attendees.']);

        // Coming without the plus-one still fits.
        $this->submit('tok_b', RsvpStatus::Accepted, 1)->assertSessionHasNoErrors();
    }

    public function test_declining_frees_the_seat_for_someone_else(): void
    {
        Notification::fake();
        $event = $this->eventWithLimit(1);
        $a = $this->guest($event, 'tok_a');
        $this->guest($event, 'tok_b');
        Rsvp::factory()->forGuest($a)->accepted(1)->create();

        $this->submit('tok_b', RsvpStatus::Accepted)->assertSessionHasErrors('status');
        $this->submit('tok_a', RsvpStatus::Declined, 0)->assertSessionHasNoErrors();
        $this->submit('tok_b', RsvpStatus::Accepted)->assertSessionHasNoErrors();
    }

    public function test_rejected_rsvp_no_longer_holds_seats(): void
    {
        Notification::fake();
        $event = $this->eventWithLimit(1);
        $a = $this->guest($event, 'tok_a');
        $this->guest($event, 'tok_b');
        Rsvp::factory()->forGuest($a)->accepted(1)->create([
            'host_approval_status' => RsvpApprovalStatus::Rejected,
        ]);

        $this->submit('tok_b', RsvpStatus::Accepted)->assertSessionHasNoErrors();
    }

    public function test_pending_rsvp_still_holds_its_seat(): void
    {
        Notification::fake();
        $event = $this->eventWithLimit(1);
        $a = $this->guest($event, 'tok_a');
        $this->guest($event, 'tok_b');
        Rsvp::factory()->forGuest($a)->accepted(1)->create([
            'host_approval_status' => RsvpApprovalStatus::Pending,
        ]);

        $this->submit('tok_b', RsvpStatus::Accepted)->assertSessionHasErrors('status');
    }

    public function test_guest_can_reduce_their_party_when_event_is_over_the_limit(): void
    {
        Notification::fake();
        // Host lowered the limit to 2 earlier; 4 seats are confirmed (2 + 2).
        $event = $this->eventWithLimit(2, plusOne: true);
        $a = $this->guest($event, 'tok_a', plusOne: true);
        $b = $this->guest($event, 'tok_b', plusOne: true);
        Rsvp::factory()->forGuest($a)->accepted(2)->create();
        Rsvp::factory()->forGuest($b)->accepted(2)->create();

        $this->submit('tok_a', RsvpStatus::Accepted, 1)->assertSessionHasNoErrors();
        $this->assertSame(1, (int) Rsvp::where('guest_id', $a->id)->value('attendee_count'));

        // Growing back is still refused.
        $this->submit('tok_a', RsvpStatus::Accepted, 2)->assertSessionHasErrors('status');
    }

    public function test_host_cannot_lower_limit_below_confirmed_seats(): void
    {
        $event = $this->eventWithLimit(10);
        $a = $this->guest($event, 'tok_a');
        Rsvp::factory()->forGuest($a)->accepted(1)->create();
        Rsvp::factory()->forGuest($this->guest($event, 'tok_b'))->accepted(1)->create();
        Rsvp::factory()->forGuest($this->guest($event, 'tok_c'))->accepted(1)->create([
            'host_approval_status' => RsvpApprovalStatus::Rejected,
        ]);
        $token = $event->user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/host/events/{$event->id}", ['guest_limit' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_limit');

        // Rejected seats don't count, so exactly the confirmed 2 is fine, and so is unlimited.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/host/events/{$event->id}", ['guest_limit' => 2])
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/host/events/{$event->id}", ['guest_limit' => null])
            ->assertOk();
    }

    public function test_limit_of_zero_is_refused(): void
    {
        $event = $this->eventWithLimit(5);
        $token = $event->user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/host/events/{$event->id}", ['guest_limit' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_limit');
    }
}
