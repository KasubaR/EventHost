<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\RsvpAwaitingApprovalNotification;
use App\Notifications\RsvpConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-token-edge-cases.md Phase 1 (pin the behaviour that already holds) and Phase 3 (a stale or
 * edited submit lands on the invitation's own status page, not the verification-link 403).
 */
class RsvpTokenEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
    }

    private function event(array $overrides = []): Event
    {
        return Event::factory()->for($this->host)->published()->create(array_merge([
            'rsvp_deadline' => null,
            'guest_limit' => null,
        ], $overrides));
    }

    private function guest(Event $event, string $token, array $overrides = []): Guest
    {
        return Guest::factory()->for($event)->create(array_merge([
            'invitation_token' => $token,
            'email' => $token.'@example.test',
        ], $overrides));
    }

    private function answer(string $status = 'accepted', int $count = 1, array $extra = []): array
    {
        return array_merge(['status' => $status, 'attendee_count' => $count, 'message' => null], $extra);
    }

    // ---------------------------------------------------------------- Phase 1: token that does not exist

    public function test_an_unknown_token_is_a_404_on_every_guest_route_and_writes_nothing(): void
    {
        $before = Rsvp::query()->count();

        foreach (['', '/thanks', '/pass', '/pass/download', '/pass.png', '/entry-pass.svg', '/entry-pass.png'] as $suffix) {
            $this->get('/rsvp/no-such-token-0123456789'.$suffix)->assertNotFound();
        }

        $this->post(route('rsvp.token.store', ['token' => 'no-such-token-0123456789']), $this->answer())->assertNotFound();

        $this->getJson(route('api.v1.rsvp.token.show', ['token' => 'no-such-token-0123456789']))->assertNotFound();
        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'no-such-token-0123456789']), $this->answer())->assertNotFound();

        $this->assertSame($before, Rsvp::query()->count());
    }

    // ---------------------------------------------------------------- Phase 1: the wrong kind of token

    public function test_a_token_of_another_kind_does_not_open_a_personal_rsvp(): void
    {
        $event = $this->event();
        $guest = $this->guest($event, 'guest-token-aaaaaaaaaaaa');

        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink(3);
        $group = $group->fresh();

        $link = EventStaffLink::factory()->for($event)->create();

        // A group token, a staff-link token and the event's slug are not guest tokens.
        $this->get(route('rsvp.token.show', ['token' => $group->rsvp_token]))->assertNotFound();
        $this->get(route('rsvp.token.show', ['token' => $link->token]))->assertNotFound();
        $this->get(route('rsvp.token.show', ['token' => $event->slug]))->assertNotFound();
        $this->post(route('rsvp.token.store', ['token' => $group->rsvp_token]), $this->answer())->assertNotFound();

        // And a guest's own token is not a group link.
        $this->get(route('group-rsvp.show', ['token' => $guest->invitation_token]))->assertNotFound();
        $this->post(route('group-rsvp.store', ['token' => $guest->invitation_token]), [
            'name' => 'X', 'email' => 'x@example.test', 'phone' => '0970000000', 'attendee_count' => 1,
        ])->assertNotFound();

        $this->assertNull($guest->fresh()->rsvp);
    }

    // ---------------------------------------------------------------- Phase 1: token of another event

    public function test_a_guest_token_from_another_event_does_not_check_in_through_this_events_staff_link(): void
    {
        $proHost = User::factory()->pro()->create();
        $eventA = Event::factory()->for($proHost)->published()->create(['rsvp_deadline' => null]);
        $eventB = Event::factory()->for($proHost)->published()->create(['rsvp_deadline' => null]);

        $guestOfA = $this->guest($eventA, 'token-of-event-a-0000000');
        $linkOfB = EventStaffLink::factory()->for($eventB)->create();

        $this->postJson(route('checkin.public.confirm-token', [
            'staffToken' => $linkOfB->token,
            'token' => $guestOfA->invitation_token,
        ]))->assertNotFound();

        $this->postJson(route('checkin.public.confirm-guest', [
            'staffToken' => $linkOfB->token,
            'guest' => $guestOfA->id,
        ]))->assertNotFound();

        $this->assertNull($guestOfA->fresh()->checked_in_at);
    }

    public function test_a_guests_rsvp_acts_on_that_guests_own_event_whatever_the_url_says(): void
    {
        $eventA = $this->event(['name' => 'Event A']);
        $eventB = $this->event(['name' => 'Event B']);
        $guest = $this->guest($eventA, 'token-of-event-a-1111111');

        $this->post(route('rsvp.token.store', ['token' => $guest->invitation_token]), $this->answer('accepted', 1, [
            'event_id' => $eventB->id,
            'guest_id' => 999999,
        ]))->assertRedirect();

        $rsvp = $guest->fresh()->rsvp;
        $this->assertNotNull($rsvp);
        $this->assertSame($eventA->id, $rsvp->event_id);
        $this->assertSame(0, Rsvp::query()->where('event_id', $eventB->id)->count());
    }

    // ---------------------------------------------------------------- Phase 1: a tampered form

    public function test_a_tampered_answer_is_refused_or_ignored_and_the_stored_rsvp_does_not_change(): void
    {
        Notification::fake();

        $event = $this->event();
        $guest = $this->guest($event, 'tamper-token-aaaaaaaaaa');
        $url = route('rsvp.token.store', ['token' => $guest->invitation_token]);

        $this->post($url, $this->answer('accepted', 1))->assertRedirect();
        $stored = $guest->fresh()->rsvp->only(['status', 'attendee_count', 'host_approval_status']);

        $this->post($url, $this->answer('garbage', 1))->assertSessionHasErrors('status');
        $this->post($url, $this->answer('accepted', 99))->assertSessionHasErrors('attendee_count');
        $this->post($url, $this->answer('accepted', -1))->assertSessionHasErrors('attendee_count');
        $this->post($url, $this->answer('accepted', 1, ['status' => ['accepted']]))->assertSessionHasErrors('status');

        // Fields no form posts are ignored, never mass-assigned.
        $this->post($url, $this->answer('accepted', 1, [
            'host_approval_status' => 'approved',
            'host_reviewed_by' => $this->host->id,
            'name' => 'Renamed',
            'email' => 'taken-over@example.test',
        ]))->assertRedirect();

        $fresh = $guest->fresh();
        $this->assertEquals($stored, $fresh->rsvp->only(['status', 'attendee_count', 'host_approval_status']));
        $this->assertNull($fresh->rsvp->host_reviewed_by);
        $this->assertNotSame('Renamed', $fresh->name);
        $this->assertSame('tamper-token-aaaaaaaaaa@example.test', $fresh->email);
    }

    public function test_a_regenerated_token_kills_the_old_link_and_the_new_one_works(): void
    {
        $event = $this->event();
        $guest = $this->guest($event, 'old-token-aaaaaaaaaaaaaa');

        $this->get(route('rsvp.token.show', ['token' => 'old-token-aaaaaaaaaaaaaa']))->assertOk();

        $guest->update(['invitation_token' => 'new-token-bbbbbbbbbbbbbb']);

        $this->get(route('rsvp.token.show', ['token' => 'old-token-aaaaaaaaaaaaaa']))->assertNotFound();
        $this->get('/rsvp/old-token-aaaaaaaaaaaaaa/pass.png')->assertNotFound();
        $this->post(route('rsvp.token.store', ['token' => 'old-token-aaaaaaaaaaaaaa']), $this->answer())->assertNotFound();
        $this->get(route('rsvp.token.show', ['token' => 'new-token-bbbbbbbbbbbbbb']))->assertOk();
    }

    public function test_the_thanks_page_of_a_token_whose_event_is_not_an_invitation_is_a_404(): void
    {
        $ticketed = Event::factory()->for($this->host)->ticketed()->published()->create();
        $guest = $this->guest($ticketed, 'ticketed-token-aaaaaaaa');
        Rsvp::query()->create([
            'event_id' => $ticketed->id,
            'guest_id' => $guest->id,
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
        ]);

        $this->get(route('rsvp.token.thanks', ['token' => $guest->invitation_token]))->assertNotFound();
    }

    // ---------------------------------------------------------------- Phase 1: duplicated requests, other channels

    public function test_a_double_tap_on_the_group_link_makes_one_guest_one_rsvp_one_notification(): void
    {
        Notification::fake();

        $event = $this->event();
        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink(3);
        $group = $group->fresh();

        $payload = ['name' => 'Mwila Banda', 'email' => 'mwila@example.test', 'phone' => '0965000111', 'attendee_count' => 1];

        $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), $payload)->assertRedirect();
        $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), $payload)->assertRedirect();

        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->where('email', 'mwila@example.test')->count());
        $this->assertSame(1, Rsvp::query()->where('event_id', $event->id)->count());
        Notification::assertSentToTimes($this->host, RsvpAwaitingApprovalNotification::class, 1);
    }

    public function test_an_api_retry_makes_one_rsvp_and_notifies_once(): void
    {
        Notification::fake();

        $event = $this->event();
        $guest = $this->guest($event, 'api-retry-token-aaaaaa');
        $url = route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]);

        $this->postJson($url, $this->answer())->assertOk();
        $this->postJson($url, $this->answer())->assertOk();

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());
        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 1);
        Notification::assertSentToTimes($this->host, NewRsvpReceivedNotification::class, 1);
    }

    // ---------------------------------------------------------------- Phase 3: stale page, right answer

    public function test_a_personal_form_left_open_across_a_delete_unpublish_or_cancel_shows_the_status_page(): void
    {
        $cases = [
            'deleted' => fn (Event $e) => $e->delete(),
            'unpublished' => fn (Event $e) => $e->forceFill(['is_published' => false])->save(),
        ];

        foreach ($cases as $name => $change) {
            $event = $this->event();
            $guest = $this->guest($event, 'stale-'.$name.'-token-aaaaaaa');
            $change($event);

            $response = $this->post(route('rsvp.token.store', ['token' => $guest->invitation_token]), $this->answer());

            $response->assertRedirect(route('rsvp.token.show', ['token' => $guest->invitation_token]));
            $this->assertNull($guest->fresh()->rsvp, "$name: nothing may be saved");

            // The page it lands on is the invitation's own status view, not the generic 403.
            $this->get(route('rsvp.token.show', ['token' => $guest->invitation_token]))
                ->assertOk()
                ->assertDontSee('Access denied');
        }
    }

    public function test_a_stale_token_submit_over_the_api_is_a_403_with_a_stable_code(): void
    {
        $event = $this->event();
        $guest = $this->guest($event, 'stale-api-token-aaaaaaa');
        $event->delete();

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => $guest->invitation_token]), $this->answer())
            ->assertForbidden()
            ->assertJsonPath('code', 'rsvp_unavailable');
    }

    public function test_an_open_form_left_open_across_a_delete_cancel_or_pause_goes_to_the_status_page(): void
    {
        $payload = ['name' => 'Late Guest', 'email' => 'late@example.test', 'phone' => '0970000001'] + $this->answer();

        $cases = [
            'deleted' => fn (Event $e) => $e->delete(),
            'cancelled' => fn (Event $e) => $e->forceFill(['cancelled_at' => now()])->save(),
            'paused' => fn (Event $e) => $e->forceFill(['invitation_paused_at' => now()])->save(),
        ];

        foreach ($cases as $name => $change) {
            $event = $this->event(['is_public' => true]);
            $change($event);

            $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
                ->assertRedirect(route('rsvp.open.show', ['slug' => $event->slug]));

            $this->assertDatabaseMissing('guests', ['event_id' => $event->id, 'email' => 'late@example.test']);
        }
    }

    public function test_an_open_form_for_a_never_published_event_is_still_a_plain_404(): void
    {
        // A draft must not confirm that it exists.
        $draft = Event::factory()->for($this->host)->create(['is_public' => true, 'is_published' => false, 'rsvp_deadline' => null]);

        $this->post(route('rsvp.open.store', ['slug' => $draft->slug]), [
            'name' => 'Nosy', 'email' => 'nosy@example.test', 'phone' => '0970000002',
        ] + $this->answer())->assertNotFound();
    }

    public function test_the_open_form_for_a_full_private_guest_list_goes_back_to_the_page_that_says_so(): void
    {
        $event = $this->event(['is_public' => false]);
        Guest::factory()->count(150)->for($event)->create();

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), [
            'name' => 'One Fifty One', 'email' => 'guest151@example.test', 'phone' => '0970000003',
        ] + $this->answer())->assertRedirect(route('rsvp.open.show', ['slug' => $event->slug]));

        $this->get(route('rsvp.open.show', ['slug' => $event->slug]))->assertSee('guest list is full');
    }
}
