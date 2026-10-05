<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\GroupRsvpResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-deadline-fixes.md, Phase 1: one definition of when RSVP closes. Every time here is written
 * on the VENUE clock (Africa/Lusaka, UTC+2), because reading the deadline as UTC was the bug.
 */
class RsvpDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private string $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venue = config('events.timezone');
    }

    private function at(string $venueTime): void
    {
        Carbon::setTestNow(Carbon::parse($venueTime, $this->venue)->utc());
    }

    private function event(array $overrides = []): Event
    {
        return Event::factory()->published()->create(array_merge([
            'user_id' => User::factory(),
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-05 18:00:00',
        ], $overrides));
    }

    // ── G1: the deadline is venue wall-clock time ────────────────────────────

    public function test_a_deadline_typed_as_1800_closes_at_1800_venue_time_not_2000(): void
    {
        $event = $this->event();

        $this->at('2026-10-05 17:59:00');
        $this->assertTrue($event->isRsvpOpen());

        // This was still open: 19:00 in Lusaka is 17:00 UTC, which is before the stored 18:00.
        $this->at('2026-10-05 19:00:00');
        $this->assertFalse($event->isRsvpOpen(), 'one hour after the deadline');

        $this->at('2026-10-05 18:00:01');
        $this->assertFalse($event->isRsvpOpen());
    }

    public function test_the_deadline_is_inclusive_at_the_exact_instant(): void
    {
        $event = $this->event();

        $this->at('2026-10-05 18:00:00');
        $this->assertTrue($event->isRsvpOpen());
    }

    public function test_the_deadline_instant_and_label_carry_the_venue_zone(): void
    {
        $event = $this->event();

        $this->assertSame('2026-10-05T18:00:00+02:00', $event->rsvpDeadlineAt()->toIso8601String());
        $this->assertSame('Monday, October 5, 2026 at 6:00 PM CAT', $event->rsvpDeadlineLabel());
        $this->assertNull($this->event(['rsvp_deadline' => null])->rsvpDeadlineLabel());
    }

    // ── G2: no deadline closes at the event start ────────────────────────────

    public function test_with_no_deadline_rsvp_closes_at_the_event_start(): void
    {
        $event = $this->event(['rsvp_deadline' => null]);

        $this->assertSame('2026-11-20T15:00:00+02:00', $event->rsvpClosesAt()->toIso8601String());

        $this->at('2026-11-20 14:59:00');
        $this->assertTrue($event->isRsvpOpen());

        $this->at('2026-11-20 16:00:00');
        $this->assertFalse($event->isRsvpOpen(), 'an hour into the event');

        $this->at('2026-11-21 01:30:00');
        $this->assertFalse($event->isRsvpOpen(), 'it used to stay open until 02:00 the next morning');
    }

    public function test_an_event_with_no_start_time_closes_at_the_end_of_its_day(): void
    {
        $event = Event::factory()->make(['event_date' => '2026-11-20', 'event_time' => null, 'rsvp_deadline' => null]);

        $this->at('2026-11-20 23:00:00');
        $this->assertTrue($event->isRsvpOpen());

        $this->at('2026-11-21 00:00:30');
        $this->assertFalse($event->isRsvpOpen());
    }

    public function test_the_deadline_wins_over_the_start_and_islocked_is_unchanged(): void
    {
        $event = $this->event();

        $this->assertSame($event->rsvpDeadlineAt()->toIso8601String(), $event->rsvpClosesAt()->toIso8601String());

        // isLocked() is date based and drives edit locking and the Ended page; it must not move.
        $this->at('2026-11-20 16:00:00');
        $this->assertFalse($event->isLocked());
    }

    // ── G9 / G13: the submit grace and the published rule ────────────────────

    public function test_submits_get_a_grace_but_pages_stay_exact(): void
    {
        $event = $this->event();

        $this->at('2026-10-05 18:00:30');
        $this->assertFalse($event->isRsvpOpen(), 'the page is exact');
        $this->assertFalse($event->acceptsRsvps());
        $this->assertTrue($event->acceptsRsvpSubmissions(), 'within the 60 second grace');

        $this->at('2026-10-05 18:01:01');
        $this->assertFalse($event->acceptsRsvpSubmissions(), 'outside the grace');
    }

    public function test_the_grace_is_configurable_and_can_be_switched_off(): void
    {
        config(['events.rsvp.deadline_grace_seconds' => 0]);
        $event = $this->event();

        $this->at('2026-10-05 18:00:01');
        $this->assertFalse($event->acceptsRsvpSubmissions());
    }

    public function test_the_grace_never_reopens_a_cancelled_paused_or_unpublished_event(): void
    {
        $this->at('2026-10-05 17:00:00');

        $this->assertFalse($this->event(['cancelled_at' => now()])->acceptsRsvpSubmissions());
        $this->assertFalse($this->event(['is_published' => false])->acceptsRsvpSubmissions());
        $this->assertTrue($this->event(['is_published' => false])->isRsvpOpen(), 'a draft preview still shows the form');
    }

    // ── The submit paths use the gate ────────────────────────────────────────

    public function test_a_token_submit_just_after_the_deadline_is_accepted_and_a_late_one_is_not(): void
    {
        $event = $this->event();
        $early = Guest::factory()->create(['event_id' => $event->id]);
        $late = Guest::factory()->create(['event_id' => $event->id]);

        $this->at('2026-10-05 18:00:20');
        $this->post(route('rsvp.token.store', $early->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertRedirect();
        $this->assertTrue(Rsvp::query()->where('guest_id', $early->id)->exists());

        $this->at('2026-10-05 18:05:00');
        $this->post(route('rsvp.token.store', $late->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertRedirect(route('rsvp.token.show', $late->invitation_token))
            ->assertSessionHas('rsvp_closed');
        $this->assertFalse(Rsvp::query()->where('guest_id', $late->id)->exists());
    }

    public function test_the_api_token_submit_follows_the_same_gate(): void
    {
        $event = $this->event();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->at('2026-10-05 18:00:20');
        $this->postJson(route('api.v1.rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertSuccessful();

        $other = Guest::factory()->create(['event_id' => $event->id]);
        $this->at('2026-10-05 19:00:00');
        $this->postJson(route('api.v1.rsvp.token.store', $other->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertForbidden();
    }

    public function test_a_group_link_page_is_exact_but_its_submit_gets_the_grace(): void
    {
        $event = $this->event();
        $group = GuestGroup::factory()->create(['event_id' => $event->id, 'seat_limit' => 5, 'rsvp_token' => str_repeat('g', 48)]);
        $resolver = app(GroupRsvpResolver::class);

        $this->at('2026-10-05 18:00:30');
        $this->assertSame(GroupRsvpResolver::CLOSED, $resolver->stateFor($group, $event, 5));
        $this->assertSame(GroupRsvpResolver::OPEN, $resolver->stateFor($group, $event, 5, submitting: true));

        $this->at('2026-10-05 18:02:00');
        $this->assertSame(GroupRsvpResolver::CLOSED, $resolver->stateFor($group, $event, 5, submitting: true));
    }

    // ── Display and API ──────────────────────────────────────────────────────

    public function test_the_closed_page_names_the_deadline_with_its_zone(): void
    {
        $event = $this->event();
        $guest = Guest::factory()->create(['event_id' => $event->id]);

        $this->at('2026-10-06 09:00:00');
        $this->get(route('rsvp.token.show', $guest->invitation_token))
            ->assertOk()
            ->assertSee('Deadline was Monday, October 5, 2026 at 6:00 PM CAT');
    }

    public function test_the_host_api_exposes_the_real_instant_and_the_closing_time(): void
    {
        $owner = User::factory()->create();
        $withDeadline = $this->event(['user_id' => $owner->id]);
        $withoutDeadline = $this->event(['user_id' => $owner->id, 'rsvp_deadline' => null]);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.v1.host.events.show', $withDeadline))
            ->assertOk()
            ->assertJsonPath('rsvp_deadline', '2026-10-05T18:00:00+02:00')
            ->assertJsonPath('rsvp_closes_at', '2026-10-05T18:00:00+02:00');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.v1.host.events.show', $withoutDeadline))
            ->assertOk()
            ->assertJsonPath('rsvp_deadline', null)
            ->assertJsonPath('rsvp_closes_at', '2026-11-20T15:00:00+02:00');
    }

    // ── Reminders count days on the venue calendar ───────────────────────────

    public function test_reminder_days_are_counted_on_the_venue_calendar_not_utc(): void
    {
        Notification::fake();
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'is_public' => true,
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-07 12:00:00',
        ]);
        $guest = Guest::factory()->for($event)->create(['email' => 'g@example.test', 'invitation_token' => null, 'rsvp_reminders_sent' => []]);

        // 01:30 on 4 Oct in Lusaka is 23:30 on 3 Oct UTC: three days before 7 Oct on the venue calendar,
        // four by the UTC calendar the command used to use.
        $this->at('2026-10-04 01:30:00');
        $this->artisan('rsvp:send-reminders')->assertSuccessful();

        $this->assertContains('3', $guest->fresh()->rsvp_reminders_sent);
    }
}
