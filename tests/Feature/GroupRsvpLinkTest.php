<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\RsvpAwaitingApprovalNotification;
use App\Notifications\RsvpConfirmationNotification;
use App\Services\RsvpApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/group-rsvp-links.md — a group's shared RSVP link with a seat pool, always held for host
 * approval, plus the host's contact number on every RSVP page.
 */
class GroupRsvpLinkTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $overrides = []): Event
    {
        return Event::factory()
            ->for(User::factory()->create(['name' => 'Chanda Host', 'phone' => '0971111111']))
            ->published()
            ->create(array_merge(['rsvp_deadline' => null, 'host_contact_phone' => '0977 123 456'], $overrides));
    }

    private function group(Event $event, int $seats = 3, array $overrides = []): GuestGroup
    {
        $group = GuestGroup::factory()->for($event)->create(array_merge(['name' => 'Committee'], $overrides));
        $group->enableLink($seats);

        return $group->fresh();
    }

    private function request(GuestGroup $group, array $overrides = [])
    {
        return $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), array_merge([
            'name' => 'Mwila Banda',
            'email' => 'mwila@example.test',
            'phone' => '0965000111',
            'attendee_count' => 1,
        ], $overrides));
    }

    public function test_open_link_shows_the_form_and_the_host_number(): void
    {
        $group = $this->group($this->event());

        $this->get(route('group-rsvp.show', ['token' => $group->rsvp_token]))
            ->assertOk()
            ->assertSee('Request my seat')
            ->assertSee('0977 123 456')
            ->assertSee('Chanda Host');
    }

    public function test_unknown_token_is_a_404(): void
    {
        $this->get(route('group-rsvp.show', ['token' => 'nope']))->assertNotFound();
    }

    public function test_request_creates_a_pending_guest_with_a_personal_link_and_tells_only_the_host(): void
    {
        Notification::fake();
        $event = $this->event(['require_rsvp_approval' => false]);
        $group = $this->group($event);

        $this->request($group)->assertRedirect();

        $guest = Guest::query()->where('email', 'mwila@example.test')->firstOrFail();
        $this->assertSame($group->id, $guest->guest_group_id);
        $this->assertNotNull($guest->invitation_token);
        $this->assertNotNull($guest->group_link_joined_at);

        $rsvp = $guest->rsvp;
        $this->assertSame(RsvpStatus::Accepted, $rsvp->status);
        $this->assertSame(RsvpApprovalStatus::Pending, $rsvp->host_approval_status);

        Notification::assertSentTo($event->user, RsvpAwaitingApprovalNotification::class);
        Notification::assertNotSentTo($event->user, RsvpConfirmationNotification::class);
        Notification::assertNothingSentTo(new AnonymousNotifiable);
    }

    public function test_pending_requests_hold_seats_until_the_pool_is_full(): void
    {
        $group = $this->group($this->event(), seats: 2);

        $this->request($group, ['email' => 'a@example.test', 'phone' => '0965000001'])->assertRedirect();
        $this->request($group, ['email' => 'b@example.test', 'phone' => '0965000002'])->assertRedirect();

        $this->assertSame(2, $group->seatsTaken());
        $this->assertSame(0, $group->seatsRemaining());

        $this->get(route('group-rsvp.show', ['token' => $group->rsvp_token]))
            ->assertOk()
            ->assertSee('All seats for Committee have been taken')
            ->assertSee('0977 123 456')
            ->assertDontSee('Request my seat');

        $this->request($group, ['email' => 'c@example.test', 'phone' => '0965000003'])
            ->assertSessionHasErrors('status');
        $this->assertSame(0, Guest::query()->where('email', 'c@example.test')->count());
    }

    public function test_rejecting_a_request_releases_its_seat(): void
    {
        Notification::fake();
        $event = $this->event();
        $group = $this->group($event, seats: 1);

        $this->request($group)->assertRedirect();
        $this->assertSame(0, $group->seatsRemaining());

        $rsvp = Rsvp::query()->firstOrFail();
        app(RsvpApprovalService::class)->reject($rsvp, $event->user, 'Not on the list');

        $this->assertSame(1, $group->seatsRemaining());
    }

    public function test_approving_a_request_keeps_its_seat_and_never_exceeds_the_pool(): void
    {
        Notification::fake();
        $event = $this->event();
        $group = $this->group($event, seats: 1);

        $this->request($group)->assertRedirect();
        app(RsvpApprovalService::class)->approve(Rsvp::query()->firstOrFail(), $event->user);

        $this->assertSame(1, $group->seatsTaken());
        $this->assertSame(0, $group->seatsRemaining());
    }

    public function test_a_plus_one_takes_two_seats_only_when_the_event_allows_it(): void
    {
        $noPlusOne = $this->group($this->event(['allow_plus_one' => false]), seats: 5);
        $this->request($noPlusOne, ['attendee_count' => 2])->assertSessionHasErrors('attendee_count');

        $withPlusOne = $this->group($this->event(['allow_plus_one' => true]), seats: 5);
        $this->request($withPlusOne, ['attendee_count' => 2])->assertRedirect();
        $this->assertSame(2, $withPlusOne->seatsTaken());
    }

    public function test_a_request_cannot_take_more_seats_than_remain(): void
    {
        $group = $this->group($this->event(['allow_plus_one' => true]), seats: 1);

        // The service says how many fit (and the form comes back with that count), instead of a bare range error.
        $this->request($group, ['attendee_count' => 2])
            ->assertSessionHasErrors(['status' => 'Only 1 seat is left for this group. You can RSVP for yourself only.'])
            ->assertSessionHasInput('attendee_count', 1);
        $this->assertSame(0, Guest::query()->count());
    }

    public function test_a_closed_link_refuses_requests_and_shows_the_host_number(): void
    {
        $group = $this->group($this->event());
        $group->forceFill(['rsvp_link_closed_at' => now()])->save();

        $this->get(route('group-rsvp.show', ['token' => $group->rsvp_token]))
            ->assertOk()
            ->assertSee('no longer taking requests')
            ->assertSee('0977 123 456');

        $this->request($group)->assertSessionHasErrors('status');
        $this->assertSame(0, Guest::query()->count());
    }

    public function test_an_email_already_on_the_list_outside_the_group_is_not_moved(): void
    {
        $event = $this->event();
        $group = $this->group($event);
        $existing = Guest::factory()->for($event)->create(['email' => 'mwila@example.test', 'guest_group_id' => null]);

        $this->request($group)->assertSessionHasErrors('email');
        $this->assertNull($existing->fresh()->guest_group_id);
    }

    public function test_a_phone_already_on_the_list_is_refused(): void
    {
        $event = $this->event();
        $group = $this->group($event);
        Guest::factory()->for($event)->create(['phone' => '0965000111']);

        $this->request($group)->assertSessionHasErrors('phone');
    }

    public function test_a_guest_the_host_added_to_the_group_is_held_to_the_seat_pool_but_not_forced_into_review(): void
    {
        Notification::fake();
        $event = $this->event(['require_rsvp_approval' => false]);
        $group = $this->group($event, seats: 1);
        $manual = Guest::factory()->for($event)->create(['guest_group_id' => $group->id, 'invitation_token' => 'tok_manual']);
        $other = Guest::factory()->for($event)->create(['guest_group_id' => $group->id, 'invitation_token' => 'tok_other']);

        $this->post(route('rsvp.token.store', ['token' => 'tok_manual']), ['status' => 'accepted', 'attendee_count' => 1])->assertRedirect();
        $this->assertSame(RsvpApprovalStatus::NotRequired, $manual->fresh()->rsvp->host_approval_status);

        $this->post(route('rsvp.token.store', ['token' => 'tok_other']), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertSessionHasErrors('status');
        $this->assertNull($other->fresh()->rsvp);
    }

    public function test_a_group_member_cannot_bypass_review_by_declining_then_accepting(): void
    {
        Notification::fake();
        $group = $this->group($this->event(), seats: 3);
        $this->request($group)->assertRedirect();
        $guest = Guest::query()->where('email', 'mwila@example.test')->firstOrFail();

        $this->post(route('rsvp.token.store', ['token' => $guest->invitation_token]), ['status' => 'declined', 'attendee_count' => 0])->assertRedirect();
        $this->post(route('rsvp.token.store', ['token' => $guest->invitation_token]), ['status' => 'accepted', 'attendee_count' => 1])->assertRedirect();

        $this->assertSame(RsvpApprovalStatus::Pending, $guest->fresh()->rsvp->host_approval_status);
    }

    public function test_the_guest_list_shows_the_approval_queue_for_a_group_link_event(): void
    {
        $event = $this->event(['require_rsvp_approval' => false]);
        $this->assertFalse($event->hasRsvpApprovalQueue());

        $this->group($event);
        $this->assertTrue($event->fresh()->hasRsvpApprovalQueue());
    }

    public function test_host_can_turn_the_link_on_close_reopen_and_turn_it_off(): void
    {
        $event = $this->event();
        $host = $event->user;
        $group = GuestGroup::factory()->for($event)->create();

        $this->actingAs($host)
            ->put(route('events.guest-groups.link.update', ['event' => $event, 'guest_group' => $group->id]), ['seat_limit' => 10])
            ->assertRedirect(route('events.guest-groups.index', $event));
        $group->refresh();
        $this->assertSame(10, $group->seat_limit);
        $this->assertNotNull($group->rsvp_token);
        $token = $group->rsvp_token;

        $this->actingAs($host)->patch(route('events.guest-groups.link.toggle', ['event' => $event, 'guest_group' => $group->id]));
        $this->assertNotNull($group->fresh()->rsvp_link_closed_at);

        $this->actingAs($host)->patch(route('events.guest-groups.link.toggle', ['event' => $event, 'guest_group' => $group->id]));
        $this->assertNull($group->fresh()->rsvp_link_closed_at);

        // Changing the seat count keeps the same address.
        $this->actingAs($host)->put(route('events.guest-groups.link.update', ['event' => $event, 'guest_group' => $group->id]), ['seat_limit' => 12]);
        $this->assertSame($token, $group->fresh()->rsvp_token);

        $this->actingAs($host)->delete(route('events.guest-groups.link.destroy', ['event' => $event, 'guest_group' => $group->id]));
        $this->assertNull($group->fresh()->rsvp_token);
        $this->get(route('group-rsvp.show', ['token' => $token]))->assertNotFound();

        $this->actingAs($host)->get(route('events.guest-groups.index', $event))->assertOk();
    }

    public function test_another_host_cannot_manage_the_link(): void
    {
        $event = $this->event();
        $group = GuestGroup::factory()->for($event)->create();

        $this->actingAs(User::factory()->create())
            ->put(route('events.guest-groups.link.update', ['event' => $event, 'guest_group' => $group->id]), ['seat_limit' => 5])
            ->assertForbidden();
        $this->assertNull($group->fresh()->rsvp_token);
    }

    public function test_seat_limit_must_be_a_positive_number(): void
    {
        $event = $this->event();
        $group = GuestGroup::factory()->for($event)->create();

        $this->actingAs($event->user)
            ->put(route('events.guest-groups.link.update', ['event' => $event, 'guest_group' => $group->id]), ['seat_limit' => 0])
            ->assertSessionHasErrors('seat_limit');
    }

    public function test_the_host_number_appears_on_the_existing_rsvp_pages(): void
    {
        $event = $this->event(['is_public' => true]);
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'tok_contact']);

        $this->get(route('rsvp.open.show', ['slug' => $event->slug]))->assertOk()->assertSee('0977 123 456');
        $this->get(route('rsvp.token.show', ['token' => 'tok_contact']))->assertOk()->assertSee('0977 123 456');

        $this->post(route('rsvp.token.store', ['token' => 'tok_contact']), ['status' => 'accepted', 'attendee_count' => 1]);
        $this->get(route('rsvp.token.thanks', ['token' => 'tok_contact']))->assertOk()->assertSee('0977 123 456');
    }

    public function test_the_host_number_falls_back_to_the_profile_phone_and_hides_when_there_is_none(): void
    {
        $event = $this->event(['host_contact_phone' => null]);
        $this->assertSame('0971111111', $event->hostContactPhone());

        $event->user->forceFill(['phone' => null])->save();
        $this->assertNull($event->fresh()->hostContactPhone());
    }

    public function test_the_create_wizard_requires_a_contact_number_for_an_invitation_event(): void
    {
        $user = User::factory()->withCredits()->create();
        $payload = [
            'name' => 'Garden Party',
            'event_type' => 'birthday',
            'audience' => 'private',
            'product_kind' => 'invitation',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
        ];

        $this->actingAs($user)->post(route('events.store'), $payload)->assertSessionHasErrors('host_contact_phone');
        $this->actingAs($user)->post(route('events.store'), $payload + ['host_contact_phone' => 'call me'])->assertSessionHasErrors('host_contact_phone');

        $this->actingAs($user)->post(route('events.store'), $payload + ['host_contact_phone' => '+260 977 123 456'])->assertSessionHasNoErrors();
        $this->assertSame('+260 977 123 456', Event::query()->where('name', 'Garden Party')->value('host_contact_phone'));
    }

    public function test_the_edit_form_cannot_blank_the_contact_number(): void
    {
        $event = $this->event();

        $this->actingAs($event->user)
            ->patch(route('events.update', $event), ['host_contact_phone' => ''])
            ->assertSessionHasErrors('host_contact_phone');
        $this->assertSame('0977 123 456', $event->fresh()->host_contact_phone);
    }
}
