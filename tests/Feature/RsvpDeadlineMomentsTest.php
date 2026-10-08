<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/rsvp-deadline-moments.md: a refusal says which kind of close it was and keeps what the guest typed, forms show
 * the cut-off time, and the host can close and reopen RSVPs by hand. Times are venue wall-clock (Africa/Lusaka).
 */
class RsvpDeadlineMomentsTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->host = User::factory()->proPlus()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'is_public' => true,
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-05 18:00:00',
            'allow_plus_one' => true,
            'guest_limit' => null,
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

    private function guest(?RsvpStatus $status = null, int $count = 1, array $overrides = []): Guest
    {
        $guest = Guest::factory()->create(array_merge(['event_id' => $this->event->id, 'plus_one_allowed' => true], $overrides));

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

    private function submit(Guest $guest, string $status, int $count, array $extra = [])
    {
        return $this->post(route('rsvp.token.store', $guest->invitation_token), array_merge(['status' => $status, 'attendee_count' => $count], $extra));
    }

    private function close(): void
    {
        $this->actingAs($this->host)->post(route('events.rsvp-closure.store', $this->event))->assertSessionHasNoErrors();
        $this->event->refresh();
    }

    // ── Phase 1: say what happened ─────────────────────────────────────────────────────────────────

    public function test_a_late_refusal_names_the_deadline_and_when_it_was(): void
    {
        $guest = $this->guest();
        $this->at('2026-10-05 18:05:00');

        $this->submit($guest, 'accepted', 1)
            ->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'deadline has passed (it was Monday, October 5, 2026 at 6:00 PM CAT)'));
    }

    public function test_the_grace_still_lets_a_slow_submit_through(): void
    {
        $guest = $this->guest();
        $this->at('2026-10-05 18:00:30');

        $this->submit($guest, 'accepted', 1)->assertSessionMissing('rsvp_closed');
        $this->assertNotNull($guest->fresh()->rsvp);
    }

    public function test_without_a_deadline_the_refusal_says_rsvp_closed_when_the_event_started(): void
    {
        $this->event->forceFill(['rsvp_deadline' => null])->save();
        $guest = $this->guest();
        $this->at('2026-11-20 15:30:00');

        $this->submit($guest, 'accepted', 1)
            ->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'RSVP closed when the event started') && ! str_contains($m, 'deadline'));
    }

    public function test_a_shortened_deadline_refuses_with_the_new_time(): void
    {
        $guest = $this->guest();
        $this->event->forceFill(['rsvp_deadline' => '2026-10-01 10:00:00'])->save();
        $this->at('2026-10-01 11:00:00');

        $this->submit($guest, 'accepted', 1)
            ->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'Thursday, October 1, 2026 at 10:00 AM CAT'));
    }

    public function test_the_api_reports_the_reason_and_the_closing_time(): void
    {
        $guest = $this->guest();
        $this->at('2026-10-06 09:00:00');

        $this->postJson(route('api.v1.rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'rsvp_closed')
            ->assertJsonPath('closed_reason', 'deadline')
            ->assertJsonPath('closes_at', '2026-10-05T18:00:00+02:00');
    }

    public function test_the_closed_page_keeps_what_the_guest_sent_and_offers_to_check_again(): void
    {
        $guest = $this->guest();
        $this->at('2026-10-06 09:00:00');

        $this->followingRedirects()
            ->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 2, 'message' => 'We love cake'])
            ->assertOk()
            ->assertSee('What you sent')
            ->assertSee('We love cake')
            ->assertSee('Attending (2 people)', false)
            ->assertSee('Check again');
    }

    public function test_a_plain_visit_to_the_closed_page_shows_no_sent_box(): void
    {
        $guest = $this->guest();
        $this->at('2026-10-06 09:00:00');

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertDontSee('What you sent');
    }

    public function test_forms_show_the_cut_off_time_in_venue_time(): void
    {
        $guest = $this->guest();

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('RSVP closes Monday, October 5, 2026 at 6:00 PM CAT');

        $this->get(route('rsvp.open.show', ['slug' => $this->event->slug]))
            ->assertOk()
            ->assertSee('RSVP closes Monday, October 5, 2026 at 6:00 PM CAT');
    }

    public function test_the_group_page_shows_the_cut_off_time(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create();
        $group->enableLink(5);

        $this->get(route('group-rsvp.show', ['token' => $group->fresh()->rsvp_token]))
            ->assertOk()
            ->assertSee('RSVP closes Monday, October 5, 2026 at 6:00 PM CAT');
    }

    public function test_a_wedding_layouts_respond_by_line_carries_the_time(): void
    {
        $line = $this->event->rsvpDeadlineAt()->format('jS F Y \a\t g:i A T');

        $this->assertSame('5th October 2026 at 6:00 PM CAT', $line);
    }

    // ── Phase 2: closing by hand ───────────────────────────────────────────────────────────────────

    public function test_the_host_closes_and_reopens_from_the_guest_list(): void
    {
        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Close RSVPs');

        $this->close();
        $this->assertTrue($this->event->rsvpManuallyClosed());
        $this->assertFalse($this->event->isRsvpOpen());
        $this->assertStringContainsString('You closed RSVPs on', (string) $this->event->rsvpClosedReason());

        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Reopen RSVPs')
            ->assertDontSee('Extend the deadline');

        $this->actingAs($this->host)->delete(route('events.rsvp-closure.destroy', $this->event))
            ->assertSessionHas('rsvp_reopened');

        $this->assertNull($this->event->fresh()->rsvp_closed_at);
        $this->assertTrue($this->event->fresh()->isRsvpOpen());
    }

    public function test_closing_twice_keeps_the_first_moment(): void
    {
        $this->close();
        $first = $this->event->rsvp_closed_at;

        $this->at('2026-10-02 09:00:00');
        $this->close();

        $this->assertTrue($first->equalTo($this->event->rsvp_closed_at));
    }

    public function test_only_the_owner_can_close_or_reopen(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->post(route('events.rsvp-closure.store', $this->event))->assertForbidden();
        $this->assertNull($this->event->fresh()->rsvp_closed_at);

        $this->close();
        $this->actingAs($other)->delete(route('events.rsvp-closure.destroy', $this->event))->assertForbidden();
        $this->assertNotNull($this->event->fresh()->rsvp_closed_at);
    }

    public function test_an_event_that_is_not_live_cannot_be_closed(): void
    {
        $draft = Event::factory()->for($this->host)->create(['is_published' => false]);
        $ended = Event::factory()->for($this->host)->published()->create(['event_date' => '2026-09-01']);

        $this->actingAs($this->host)->post(route('events.rsvp-closure.store', $draft))->assertSessionHasErrors('event');
        $this->actingAs($this->host)->post(route('events.rsvp-closure.store', $ended))->assertSessionHasErrors('event');
        $this->assertNull($draft->fresh()->rsvp_closed_at);
        $this->assertNull($ended->fresh()->rsvp_closed_at);
    }

    public function test_a_manual_close_refuses_new_answers_at_once_with_no_grace(): void
    {
        $guest = $this->guest();
        $this->close();

        $this->submit($guest, 'accepted', 1)
            ->assertRedirect(route('rsvp.token.show', $guest->invitation_token))
            ->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'The host has stopped taking responses'));

        $this->assertNull($guest->fresh()->rsvp);

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('the host has stopped taking responses for this event')
            ->assertDontSee('Deadline was');
    }

    public function test_a_guest_who_answered_can_still_cancel_or_reduce_but_not_increase(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, 2);
        $this->close();

        $this->submit($guest, 'accepted', 1)->assertSessionMissing('rsvp_closed');
        $this->assertSame(1, (int) $guest->fresh()->rsvp->attendee_count);

        $this->submit($guest, 'accepted', 2)->assertSessionHas('rsvp_closed');
        $this->assertSame(1, (int) $guest->fresh()->rsvp->attendee_count);

        $this->submit($guest, 'declined', 0)->assertSessionMissing('rsvp_closed');
        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);

        $this->submit($guest, 'accepted', 1)->assertSessionHas('rsvp_closed');
        $this->assertSame(RsvpStatus::Declined, $guest->fresh()->rsvp->status);
    }

    public function test_the_closed_page_offers_a_manually_closed_guest_the_cancel_buttons(): void
    {
        $guest = $this->guest(RsvpStatus::Accepted, 2);
        $this->close();

        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('Cancel my RSVP');
    }

    public function test_the_open_form_and_the_api_follow_a_manual_close(): void
    {
        $this->close();

        $this->post(route('rsvp.open.store', ['slug' => $this->event->slug]), [
            'name' => 'Sam Open', 'email' => 'sam@example.com', 'phone' => '0977123456', 'status' => 'accepted', 'attendee_count' => 1,
        ])->assertSessionHas('rsvp_closed', fn ($m) => str_contains($m, 'host has stopped'));
        $this->assertSame(0, Guest::query()->where('email', 'sam@example.com')->count());

        $guest = $this->guest();
        $this->postJson(route('api.v1.rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'rsvp_closed')
            ->assertJsonPath('closed_reason', 'host')
            ->assertJsonPath('closes_at', null);
    }

    public function test_a_group_link_stops_taking_requests(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create();
        $group->enableLink(5);
        $group = $group->fresh();
        $this->close();

        $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), [
            'name' => 'Mwila Banda', 'email' => 'mwila@example.test', 'phone' => '0965000111', 'attendee_count' => 1,
        ])->assertSessionHasErrors('status');

        $this->assertSame(0, Guest::query()->where('email', 'mwila@example.test')->count());
    }

    public function test_extending_the_deadline_does_not_reopen_a_manual_close(): void
    {
        $this->close();

        $this->event->forceFill(['rsvp_deadline' => '2026-11-01 18:00:00'])->save();

        $this->assertFalse($this->event->fresh()->isRsvpOpen());
    }

    public function test_the_deadline_reminders_skip_a_manually_closed_event(): void
    {
        $this->event->forceFill(['rsvp_deadline' => '2026-10-04 18:00:00'])->save();
        $guest = $this->guest(null, 1, ['email' => 'late@example.test', 'invitation_token' => null, 'rsvp_reminders_sent' => []]);
        $this->close();

        $this->at('2026-10-01 09:00:00');
        $this->artisan('rsvp:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent ?? []);
    }

    public function test_invitations_and_reminders_are_refused_while_closed(): void
    {
        $guest = $this->guest(null, 1, ['email' => 'g@example.test']);
        $this->close();

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'send_reminder_email', 'guest_ids' => [$guest->id]])
            ->assertSessionHasErrors();

        Notification::assertNothingSent();
    }

    public function test_pausing_is_still_a_different_thing(): void
    {
        $this->actingAs($this->host)->patch(route('events.pause', $this->event))->assertSessionHasNoErrors();

        $this->assertNull($this->event->fresh()->rsvp_closed_at);
        $this->assertTrue($this->event->fresh()->isInvitationPaused());
    }

    public function test_the_api_closes_and_reopens_and_reports_the_instant(): void
    {
        Sanctum::actingAs($this->host);

        $this->postJson(route('api.v1.host.events.rsvp-closure.store', $this->event))
            ->assertOk()
            ->assertJsonPath('rsvp_closed_at', fn ($v) => is_string($v));
        $this->assertTrue($this->event->fresh()->rsvpManuallyClosed());

        $this->deleteJson(route('api.v1.host.events.rsvp-closure.destroy', $this->event))
            ->assertOk()
            ->assertJsonPath('rsvp_closed_at', null);
        $this->assertFalse($this->event->fresh()->rsvpManuallyClosed());
    }

    public function test_the_api_refuses_to_close_an_unpublished_event_and_a_stranger(): void
    {
        $draft = Event::factory()->for($this->host)->create(['is_published' => false]);

        Sanctum::actingAs($this->host);
        $this->postJson(route('api.v1.host.events.rsvp-closure.store', $draft))
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_lifecycle_transition');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson(route('api.v1.host.events.rsvp-closure.store', $this->event))->assertForbidden();
    }

    public function test_a_host_override_still_works_while_closed(): void
    {
        $guest = $this->guest();
        $this->close();

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.set', ['event' => $this->event, 'guest' => $guest->id]), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(RsvpStatus::Accepted, $guest->fresh()->rsvp->status);
    }
}
