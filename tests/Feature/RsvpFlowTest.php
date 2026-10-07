<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\RsvpConfirmationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RsvpFlowTest extends TestCase
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

    public function test_token_rsvp_second_submit_updates_same_row(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => null,
        ]);

        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_test_fixed_token',
            'email' => 'guest@example.test',
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_test_fixed_token']), $this->rsvpPayload(RsvpStatus::Accepted, 1))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_test_fixed_token']));

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());

        $this->post(route('rsvp.token.store', ['token' => 'tok_test_fixed_token']), $this->rsvpPayload(RsvpStatus::Declined, 0))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_test_fixed_token']));

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());
        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp?->status);

        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 2);
        Notification::assertSentToTimes($user, NewRsvpReceivedNotification::class, 2);
    }

    public function test_an_identical_resubmit_notifies_nobody_again(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => null,
        ]);

        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_dup_submit',
            'email' => 'dup@example.test',
        ]);

        $payload = ['status' => 'accepted', 'attendee_count' => 1, 'message' => 'See you there'];

        $this->post(route('rsvp.token.store', ['token' => 'tok_dup_submit']), $payload)->assertRedirect();
        $updatedAt = $guest->fresh()->rsvp->updated_at;

        $this->travel(5)->minutes();
        $this->post(route('rsvp.token.store', ['token' => 'tok_dup_submit']), $payload)
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_dup_submit']));

        $this->assertSame(1, Rsvp::query()->where('guest_id', $guest->id)->count());
        $this->assertEquals($updatedAt, $guest->fresh()->rsvp->updated_at, 'an unchanged resubmit must not rewrite the row');
        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 1);
        Notification::assertSentToTimes($user, NewRsvpReceivedNotification::class, 1);

        // A real change still notifies.
        $this->post(route('rsvp.token.store', ['token' => 'tok_dup_submit']), [...$payload, 'message' => 'Running late'])->assertRedirect();

        Notification::assertSentOnDemandTimes(RsvpConfirmationNotification::class, 2);
        Notification::assertSentToTimes($user, NewRsvpReceivedNotification::class, 2);
    }

    public function test_a_refused_open_rsvp_leaves_no_guest_behind(): void
    {
        Notification::fake();

        $event = Event::factory()->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'guest_limit' => 1,
        ]);
        $taker = Guest::factory()->for($event)->create(['email' => 'taker@example.test']);
        Rsvp::query()->create([
            'event_id' => $event->id,
            'guest_id' => $taker->id,
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
        ]);
        $existing = Guest::factory()->for($event)->create(['name' => 'Original Name', 'email' => 'existing@example.test', 'phone' => '0971111111']);

        $new = array_merge(['name' => 'Newcomer', 'email' => 'new@example.test', 'phone' => '0972222222'], $this->rsvpPayload(RsvpStatus::Accepted, 1));
        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $new)->assertSessionHasErrors('status');

        $this->assertDatabaseMissing('guests', ['event_id' => $event->id, 'email' => 'new@example.test']);

        $changed = array_merge(['name' => 'Renamed', 'email' => 'existing@example.test', 'phone' => '0973333333'], $this->rsvpPayload(RsvpStatus::Accepted, 1));
        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $changed)->assertSessionHasErrors('status');

        $existing->refresh();
        $this->assertSame('Original Name', $existing->name, 'a refused submit must not rewrite the guest');
        $this->assertSame('0971111111', $existing->phone);
        $this->assertSame(2, Guest::query()->where('event_id', $event->id)->count());
    }

    public function test_the_open_rsvp_throttle_follows_the_guests_email_not_the_whole_ip(): void
    {
        Notification::fake();

        $event = Event::factory()->published()->create(['is_public' => true, 'rsvp_deadline' => null, 'guest_limit' => null]);
        $url = route('rsvp.open.store', ['slug' => $event->slug]);
        $payload = fn (string $email) => array_merge(['name' => 'A Guest', 'email' => $email], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        for ($i = 0; $i < 10; $i++) {
            $this->post($url, $payload('one@example.test'))->assertRedirect();
        }

        $this->post($url, $payload('one@example.test'))
            ->assertStatus(429)
            ->assertSee('Please wait a moment');

        // Another guest on the same IP is not locked out by it.
        $this->post($url, $payload('two@example.test'))->assertRedirect();
    }

    public function test_token_rsvp_rejected_after_deadline(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => now()->subDay(),
        ]);

        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_closed_deadline',
        ]);

        // Not a bare 403 any more: back to the closed page, with the reason (plans/rsvp-deadline-fixes.md G4).
        $this->post(route('rsvp.token.store', ['token' => 'tok_closed_deadline']), $this->rsvpPayload(RsvpStatus::Accepted))
            ->assertRedirect(route('rsvp.token.show', ['token' => 'tok_closed_deadline']))
            ->assertSessionHas('rsvp_closed');

        $this->assertNull($guest->fresh()->rsvp);
    }

    public function test_guest_limit_blocks_additional_acceptance(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'guest_limit' => 2,
            'rsvp_deadline' => null,
        ]);

        $g1 = Guest::factory()->for($event)->create(['invitation_token' => 'tok_cap_a']);
        $g2 = Guest::factory()->for($event)->create(['invitation_token' => 'tok_cap_b']);

        Rsvp::factory()->forGuest($g1)->accepted(2)->create();

        $this->post(route('rsvp.token.store', ['token' => 'tok_cap_b']), $this->rsvpPayload(RsvpStatus::Accepted, 1))
            ->assertSessionHasErrors('status');
    }

    public function test_open_rsvp_updates_same_guest_by_email(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
        ]);

        $payload = array_merge([
            'name' => 'Jamie Guest',
            'email' => 'jamie@example.test',
            'phone' => null,
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertRedirectContains('/rsvp/confirmed/');

        $guest = Guest::query()->where('event_id', $event->id)->where('email', 'jamie@example.test')->first();
        $this->assertNotNull($guest);

        $payload['status'] = RsvpStatus::Maybe->value;
        $payload['attendee_count'] = 0;

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertRedirectContains('/rsvp/confirmed/');

        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->where('email', 'jamie@example.test')->count());
        $this->assertSame(RsvpStatus::Maybe, $guest->fresh()->rsvp?->status);
    }

    /**
     * A private event's custom URL (/e/{slug}) is unlisted, not unreachable — a
     * host shares it directly (e.g. in a family WhatsApp group) instead of adding
     * every guest by hand. Unlike the public/free-registration open-RSVP flow
     * below, a guest here always gets a real invitation_token, so they get an
     * entry pass and a personal "view/change RSVP" link back.
     */
    public function test_open_rsvp_lets_a_private_events_guest_self_rsvp_with_a_personal_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => false,
            'rsvp_deadline' => null,
        ]);

        $this->get(route('rsvp.open.show', ['slug' => $event->slug]))
            ->assertOk();

        $this->get(route('rsvp.open.show', ['slug' => $event->slug, 'status' => 'declined']))
            ->assertOk()
            ->assertSee('value="declined" checked', false)
            ->assertSee('value="0" selected', false)
            ->assertDontSee('value="accepted" checked', false);

        $this->get(route('rsvp.open.show', ['slug' => $event->slug, 'status' => 'not-a-status']))
            ->assertOk()
            ->assertDontSee('value="accepted" checked', false)
            ->assertDontSee('value="declined" checked', false)
            ->assertDontSee('value="maybe" checked', false);

        $payload = array_merge([
            'name' => 'Family Member',
            'email' => 'family@example.test',
            'phone' => '+260971111111',
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $response = $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload);

        $guest = Guest::query()->where('event_id', $event->id)->where('email', 'family@example.test')->first();
        $this->assertNotNull($guest);
        $this->assertNotNull($guest->invitation_token);

        $response->assertRedirect(route('rsvp.token.thanks', ['token' => $guest->invitation_token]));
        $this->assertSame(RsvpStatus::Accepted, $guest->rsvp?->status);
    }

    public function test_open_rsvp_requires_phone_for_a_private_event_but_not_a_public_one(): void
    {
        $private = Event::factory()->published()->create(['is_public' => false, 'rsvp_deadline' => null]);
        $public = Event::factory()->published()->create(['is_public' => true, 'rsvp_deadline' => null]);

        $payload = array_merge([
            'name' => 'No Phone',
            'email' => 'nophone-private@example.test',
            'phone' => null,
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.open.store', ['slug' => $private->slug]), $payload)
            ->assertSessionHasErrors('phone');
        $this->assertDatabaseMissing('guests', ['event_id' => $private->id, 'email' => 'nophone-private@example.test']);

        $payload['email'] = 'nophone-public@example.test';
        $this->post(route('rsvp.open.store', ['slug' => $public->slug]), $payload)
            ->assertSessionDoesntHaveErrors('phone');
        $this->assertDatabaseHas('guests', ['event_id' => $public->id, 'email' => 'nophone-public@example.test']);

        // The public guest keeps the existing token-less, anonymous-headcount shape.
        $publicGuest = Guest::query()->where('event_id', $public->id)->where('email', 'nophone-public@example.test')->first();
        $this->assertNull($publicGuest->invitation_token);
    }

    public function test_open_rsvp_blocks_a_phone_already_on_the_private_guest_list(): void
    {
        $event = Event::factory()->published()->create(['is_public' => false, 'rsvp_deadline' => null]);
        Guest::factory()->for($event)->create(['name' => 'Existing Guest', 'email' => 'existing@example.test', 'phone' => '0971234567']);

        $payload = array_merge([
            'name' => 'New Name Same Number',
            'email' => 'different@example.test',
            // Same Zambian number, international format instead of local.
            'phone' => '+260 97 123 4567',
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('guests', ['event_id' => $event->id, 'email' => 'different@example.test']);
        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->count());
    }

    public function test_open_rsvp_lets_a_guest_resubmit_with_their_own_matching_phone(): void
    {
        Notification::fake();

        $event = Event::factory()->published()->create(['is_public' => false, 'rsvp_deadline' => null]);
        $guest = Guest::factory()->for($event)->create(['name' => 'Returning Guest', 'email' => 'returning@example.test', 'phone' => '0971234567']);

        $payload = array_merge([
            'name' => 'Returning Guest',
            'email' => 'returning@example.test',
            'phone' => '+260 97 123 4567',
        ], $this->rsvpPayload(RsvpStatus::Declined, 0));

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertSessionDoesntHaveErrors('phone');

        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->count());
        $this->assertSame(RsvpStatus::Declined, $guest->rsvp?->fresh()->status);
    }

    public function test_open_rsvp_allows_a_duplicate_phone_on_a_public_event(): void
    {
        $event = Event::factory()->published()->create(['is_public' => true, 'rsvp_deadline' => null]);
        Guest::factory()->for($event)->create(['email' => 'first@example.test', 'phone' => '0971234567']);

        $payload = array_merge([
            'name' => 'Second Household Member',
            'email' => 'second@example.test',
            'phone' => '+260 97 123 4567',
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertSessionDoesntHaveErrors('phone');

        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'email' => 'second@example.test']);
    }

    public function test_open_rsvp_respects_the_plan_guest_capacity_for_a_private_event(): void
    {
        $owner = User::factory()->create(); // Base tier, cap 150
        $event = Event::factory()->for($owner)->published()->create([
            'is_public' => false,
            'rsvp_deadline' => null,
        ]);
        Guest::factory()->count(150)->for($event)->create();

        $this->get(route('rsvp.open.show', ['slug' => $event->slug]))
            ->assertOk()
            ->assertSee('guest list is full');

        $payload = array_merge([
            'name' => 'One Fifty One',
            'email' => 'guest151@example.test',
            'phone' => '+260970000000',
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertForbidden();

        $this->assertSame(150, $event->guests()->count());
    }

    public function test_a_returning_private_event_guest_is_not_blocked_by_a_full_capacity(): void
    {
        $event = Event::factory()->published()->create(['is_public' => false, 'rsvp_deadline' => null]);
        Guest::factory()->count(149)->for($event)->create();
        Guest::factory()->for($event)->create([
            'email' => 'returning@example.test',
            'invitation_token' => 'existing_token_123',
        ]);

        $payload = array_merge([
            'name' => 'Returning Guest',
            'email' => 'returning@example.test',
            'phone' => '+260972222222',
        ], $this->rsvpPayload(RsvpStatus::Maybe, 0));

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload)
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'existing_token_123']));

        $this->assertSame(150, $event->guests()->count());
    }

    public function test_token_rsvp_works_when_event_is_private(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => false,
            'rsvp_deadline' => null,
        ]);

        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_private_event_ok',
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_private_event_ok']), $this->rsvpPayload(RsvpStatus::Accepted))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_private_event_ok']));

        $this->assertNotNull($guest->fresh()->rsvp);
    }

    /**
     * A guest's personal link has to show the actual designed invitation — not
     * just a bare form they can't judge without context — same as the (now also
     * reachable, but unlisted) /e/{slug} page for the event.
     */
    public function test_token_rsvp_page_shows_the_designed_invitation_not_a_bare_form(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => false,
            'rsvp_deadline' => null,
            'description' => 'Join us as we celebrate this milestone together.',
            'venue' => 'The Garden Hall',
        ]);

        $guest = Guest::factory()->for($event)->create([
            'name' => 'Jane Guest',
            'invitation_token' => 'tok_shows_invitation',
        ]);

        $response = $this->get(route('rsvp.token.show', ['token' => 'tok_shows_invitation']));

        $response->assertOk();
        $response->assertSee($event->name);
        $response->assertSee('Join us as we celebrate this milestone together.');
        $response->assertSee('The Garden Hall');
        $response->assertSee('Jane Guest');
        $response->assertDontSee('This host marked this event as private in settings.');
    }

    public function test_token_rsvp_thanks_page_shows_event_and_response_details(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'name' => 'Sarah & David\'s Wedding',
            'venue' => 'The Garden Hall',
            'allow_plus_one' => true,
        ]);

        $guest = Guest::factory()->for($event)->create([
            'name' => 'John Guest',
            'invitation_token' => 'tok_thanks_details',
            'email' => 'john@example.test',
            'plus_one_allowed' => true,
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_thanks_details']), $this->rsvpPayload(RsvpStatus::Accepted, 2))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_thanks_details']));

        $response = $this->get(route('rsvp.token.thanks', ['token' => 'tok_thanks_details']));

        $response->assertOk();
        $response->assertSee('Thank you, John Guest!');
        $response->assertSee('Sarah &amp; David&#039;s Wedding', false);
        $response->assertSee('The Garden Hall');
        $response->assertSee('Attending');
        $response->assertSee('Guests: 2');
        // Refreshable: re-fetched fresh from the token, not a one-shot flash — visiting
        // again (simulating a reload/bookmark) must show the same details, not a blank state.
        $again = $this->get(route('rsvp.token.thanks', ['token' => 'tok_thanks_details']));
        $again->assertOk();
        $again->assertSee('Thank you, John Guest!');
    }

    public function test_token_thanks_page_redirects_to_form_when_guest_has_not_responded_yet(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['is_public' => true]);

        Guest::factory()->for($event)->create(['invitation_token' => 'tok_no_response_yet']);

        $this->get(route('rsvp.token.thanks', ['token' => 'tok_no_response_yet']))
            ->assertRedirect(route('rsvp.token.show', ['token' => 'tok_no_response_yet']));
    }

    public function test_open_rsvp_confirmation_survives_a_refresh_and_rejects_a_tampered_link(): void
    {
        Notification::fake();

        $event = Event::factory()->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
            'name' => 'Demo Party',
        ]);

        $payload = array_merge([
            'name' => 'Jamie Guest',
            'email' => 'jamie-thanks@example.test',
            'phone' => null,
        ], $this->rsvpPayload(RsvpStatus::Accepted, 1));

        $response = $this->post(route('rsvp.open.store', ['slug' => $event->slug]), $payload);
        $response->assertRedirectContains('/rsvp/confirmed/');
        $url = $response->headers->get('Location');

        // Unlike the old one-shot flash, the same link works again.
        $this->get($url)->assertOk()->assertSee('Thank you, Jamie Guest!')->assertSee('Demo Party');
        $this->get($url)->assertOk()->assertSee('Thank you, Jamie Guest!');

        // A tampered signature is refused.
        $this->get($url.'0')->assertForbidden();

        // An expired link is refused too.
        $this->travel(2)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_the_open_form_starts_with_no_answer_chosen_but_a_returning_guest_keeps_theirs(): void
    {
        $event = Event::factory()->published()->create(['is_public' => true, 'rsvp_deadline' => null]);

        $html = $this->get(route('rsvp.open.show', ['slug' => $event->slug]))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="status"[^>]*checked/', $html, 'no answer should be pre-ticked');

        $this->get(route('rsvp.open.show', ['slug' => $event->slug, 'status' => 'declined']))
            ->assertSee('value="declined" checked', false);
    }

    public function test_a_submit_with_no_answer_goes_back_to_the_form_with_a_clear_message(): void
    {
        $event = Event::factory()->published()->create(['is_public' => true, 'rsvp_deadline' => null]);

        $response = $this->from(route('events.public', ['slug' => $event->slug]))
            ->post(route('rsvp.open.store', ['slug' => $event->slug]), [
                'name' => 'No Answer',
                'email' => 'noanswer@example.test',
                'attendee_count' => 1,
            ]);

        $response->assertSessionHasErrors(['status' => 'Please choose whether you can come.']);
        $this->assertStringEndsWith('#rsvp', $response->headers->get('Location'));
        $this->assertDatabaseMissing('guests', ['email' => 'noanswer@example.test']);
    }

    public function test_host_notification_skipped_when_disabled_in_preferences(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'notification_preferences' => array_merge(User::DEFAULT_NOTIFICATION_PREFERENCES, [
                'email_rsvp_updates' => false,
            ]),
        ]);

        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => null,
        ]);

        $guest = Guest::factory()->for($event)->create([
            'invitation_token' => 'tok_pref_off',
            'email' => 'x@example.test',
        ]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_pref_off']), $this->rsvpPayload(RsvpStatus::Accepted))
            ->assertRedirect(route('rsvp.token.thanks', ['token' => 'tok_pref_off']));

        Notification::assertNothingSentTo($user);
    }
}
