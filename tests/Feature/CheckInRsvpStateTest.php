<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-status-changes.md Phase 2: the door respects the RSVP. A guest who declined or whom the host rejected is
 * not checked in by a scan (the host's own scanner can override, and the override is written down); Maybe, awaiting
 * approval and no answer are let in with a warning. A guest who is already inside cannot decline or reduce.
 */
class CheckInRsvpStateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = User::factory()->pro()->create();
        // Starts in two hours: the door is already open (it opens 24 h before) while RSVP reductions still are too,
        // which is exactly the window where a guest could be checked in and decline.
        $start = Carbon::now(config('events.timezone'))->addHours(2);

        $this->event = Event::factory()->for($this->owner)->published()->create([
            'event_date' => $start->toDateString(),
            'event_time' => $start->format('H:i:s'),
            'rsvp_deadline' => null,
            'allow_plus_one' => true,
        ]);
    }

    private function guest(?RsvpStatus $status, ?RsvpApprovalStatus $approval = null, int $seats = 1): Guest
    {
        $guest = Guest::factory()->for($this->event)->create(['plus_one_allowed' => true]);

        if ($status !== null) {
            Rsvp::query()->create([
                'event_id' => $this->event->id,
                'guest_id' => $guest->id,
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? $seats : 0,
                'host_approval_status' => $approval ?? RsvpApprovalStatus::NotRequired,
            ]);
        }

        return $guest;
    }

    private function hostScan(Guest $guest, string $query = '')
    {
        return $this->actingAs($this->owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $this->event, 'token' => $guest->invitation_token]).$query);
    }

    // ---------------------------------------------------------------- refused: declined and rejected

    public function test_a_declined_guest_is_not_checked_in_by_a_scan(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);

        $this->hostScan($guest)
            ->assertForbidden()
            ->assertJsonPath('can_override', true)
            ->assertJsonFragment(['message' => $guest->name.' declined this invitation, so they were not checked in.']);

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_a_guest_the_host_rejected_is_not_checked_in_by_a_scan(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, RsvpApprovalStatus::Rejected);

        $this->hostScan($guest)->assertForbidden()->assertJsonPath('can_override', true);

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_an_emailed_pass_for_a_guest_who_later_declined_stops_scanning(): void
    {
        // The PDF/PNG attachment stays valid forever; only the server can know the guest declined since.
        $guest = $this->guest(RsvpStatus::Accepted);
        $guest->rsvp->update(['status' => RsvpStatus::Declined, 'attendee_count' => 0]);

        $this->hostScan($guest->fresh())->assertForbidden();
        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_a_staff_link_scanner_refuses_and_cannot_override(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);
        $link = EventStaffLink::factory()->for($this->event)->create();

        $response = $this->postJson(route('checkin.public.confirm-token', ['staffToken' => $link->token, 'token' => $guest->invitation_token]).'?override=1');

        $response->assertForbidden()->assertJsonMissingPath('can_override');
        $this->assertNull($guest->fresh()->checked_in_at, 'an anonymous staff link must ignore ?override=1');
    }

    public function test_the_host_can_check_a_refused_guest_in_anyway_and_it_is_written_down(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);

        $this->hostScan($guest, '?override=1')
            ->assertOk()
            ->assertJsonPath('rsvp_status', 'declined')
            ->assertJsonPath('rsvp_warning', 'Guest declined this invitation.');

        $guest->refresh();
        $this->assertNotNull($guest->checked_in_at);
        $this->assertStringContainsString('host override: declined', (string) $guest->checked_in_via_label);
    }

    public function test_a_repeat_scan_of_someone_already_inside_is_not_refused(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $this->hostScan($guest)->assertOk();

        $guest->rsvp->update(['status' => RsvpStatus::Declined, 'attendee_count' => 0]);

        $this->hostScan($guest->fresh())->assertOk()->assertJsonPath('already_checked_in', true);
    }

    // ---------------------------------------------------------------- let in, with a warning

    public function test_an_approved_or_not_required_accepted_guest_checks_in_without_a_warning(): void
    {
        $this->hostScan($this->guest(RsvpStatus::Accepted))
            ->assertOk()
            ->assertJsonPath('rsvp_status', 'accepted')
            ->assertJsonPath('rsvp_warning', null);

        $this->hostScan($this->guest(RsvpStatus::Accepted, RsvpApprovalStatus::Approved))
            ->assertOk()
            ->assertJsonPath('rsvp_warning', null);
    }

    public function test_maybe_awaiting_approval_and_no_answer_are_let_in_with_a_warning(): void
    {
        $this->hostScan($this->guest(RsvpStatus::Maybe))
            ->assertOk()->assertJsonPath('rsvp_status', 'maybe')->assertJsonPath('rsvp_warning', 'Answered "Maybe": RSVP not confirmed.');

        $this->hostScan($this->guest(RsvpStatus::Accepted, RsvpApprovalStatus::Pending))
            ->assertOk()->assertJsonPath('rsvp_status', 'accepted')->assertJsonPath('rsvp_warning', "RSVP is still waiting for the host's approval.");

        $guest = $this->guest(null);
        $this->hostScan($guest)
            ->assertOk()->assertJsonPath('rsvp_status', 'none')->assertJsonPath('rsvp_warning', 'No RSVP on record for this guest.');
        $this->assertNotNull($guest->fresh()->checked_in_at);
    }

    public function test_the_api_scanner_refuses_and_overrides_the_same_way(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);
        $headers = ['Authorization' => 'Bearer '.$this->owner->createToken('t')->plainTextToken];
        $url = route('api.v1.host.events.checkin.confirm-token', ['event' => $this->event, 'token' => $guest->invitation_token]);

        $this->withHeaders($headers)->postJson($url)->assertForbidden()->assertJsonPath('can_override', true);
        $this->withHeaders($headers)->postJson($url.'?override=1')->assertOk()->assertJsonPath('rsvp_status', 'declined');
        $this->assertNotNull($guest->fresh()->checked_in_at);
    }

    public function test_a_closed_door_is_still_not_overridable(): void
    {
        $this->event->update(['event_date' => now()->addYear()->toDateString()]);
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->hostScan($guest, '?override=1')->assertForbidden()->assertJsonPath('can_override', false);
    }

    // ---------------------------------------------------------------- already inside: no decline or reduce

    public function test_a_guest_who_is_checked_in_cannot_decline_or_reduce(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, seats: 2);
        $guest->forceFill(['checked_in_at' => now()])->save();
        $url = route('rsvp.token.store', $guest->invitation_token);

        foreach ([['declined', 0], ['maybe', 0], ['accepted', 1]] as [$status, $count]) {
            $this->post($url, ['status' => $status, 'attendee_count' => $count])
                ->assertRedirect(route('rsvp.token.show', $guest->invitation_token))
                ->assertSessionHasErrors(['status' => 'You are already checked in, so this response can no longer be cancelled or reduced here. Please speak to the host.']);
        }

        $rsvp = $guest->fresh()->rsvp;
        $this->assertSame(RsvpStatus::Accepted, $rsvp->status);
        $this->assertSame(2, $rsvp->attendee_count);
    }

    public function test_the_api_says_why_with_a_stable_code(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);
        $guest->forceFill(['checked_in_at' => now()])->save();

        $this->postJson(route('api.v1.rsvp.token.store', $guest->invitation_token), ['status' => 'declined', 'attendee_count' => 0])
            ->assertForbidden()
            ->assertJsonPath('code', 'rsvp_checked_in');
    }

    public function test_a_checked_in_guest_can_still_resubmit_the_same_answer_or_ask_for_more_seats(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, seats: 1);
        $guest->forceFill(['checked_in_at' => now()])->save();
        $url = route('rsvp.token.store', $guest->invitation_token);

        $this->post($url, ['status' => 'accepted', 'attendee_count' => 1])
            ->assertRedirect(route('rsvp.token.thanks', $guest->invitation_token))
            ->assertSessionHasNoErrors();

        $this->post($url, ['status' => 'accepted', 'attendee_count' => 2])->assertSessionHasNoErrors();
        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_a_guest_the_host_let_in_despite_declining_can_resubmit_their_decline(): void
    {
        $guest = $this->guest(RsvpStatus::Declined);
        $this->hostScan($guest, '?override=1')->assertOk();

        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'declined', 'attendee_count' => 0])
            ->assertSessionHasNoErrors();
    }

    public function test_a_guest_who_is_not_checked_in_can_still_decline_as_before(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted);

        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'declined', 'attendee_count' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);
    }
}
