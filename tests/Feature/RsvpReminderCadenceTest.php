<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\RsvpReminderNotification;
use App\Support\RsvpReminderBuckets;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-deadline-fixes.md, Phase 3: RSVP-deadline reminders follow the deadline. A reminder goes out
 * when the deadline is inside a 7 / 3 / 1 day window the guest has not used yet (catch-up), and moving or
 * removing the deadline starts the cadence again. Times are venue wall-clock (Africa/Lusaka).
 */
class RsvpReminderCadenceTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->event = Event::factory()->for(User::factory()->proPlus())->published()->create([
            'is_public' => true,
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-20 18:00:00',
        ]);
    }

    private function guest(array $overrides = []): Guest
    {
        return Guest::factory()->create(array_merge([
            'event_id' => $this->event->id,
            'email' => fake()->unique()->safeEmail(),
            'invitation_token' => null,
            'rsvp_reminders_sent' => [],
        ], $overrides));
    }

    /** Run the daily command as the 09:00 Lusaka job does, on the given venue date. */
    private function runOn(string $venueDate): void
    {
        Carbon::setTestNow(Carbon::parse($venueDate.' 09:00:00', config('events.timezone'))->utc());
        $this->artisan('rsvp:send-reminders')->assertSuccessful();
    }

    private function mailsFor(Guest $guest): int
    {
        return NotificationLog::query()->where('guest_id', $guest->id)->where('type', 'rsvp_reminder')->where('status', NotificationLog::STATUS_SENT)->count();
    }

    private function lastDays(Guest $guest): ?int
    {
        return NotificationLog::query()->where('guest_id', $guest->id)->where('type', 'rsvp_reminder')->latest('id')->first()?->meta['days_until_deadline'] ?? null;
    }

    // ── The windows ──────────────────────────────────────────────────────────

    public function test_the_window_helper_matches_the_cadence(): void
    {
        $this->assertSame([], RsvpReminderBuckets::eligibleFor(8));
        $this->assertSame(['7'], RsvpReminderBuckets::eligibleFor(7));
        $this->assertSame(['7'], RsvpReminderBuckets::eligibleFor(4));
        $this->assertSame(['7', '3'], RsvpReminderBuckets::eligibleFor(3));
        $this->assertSame(['7', '3'], RsvpReminderBuckets::eligibleFor(2));
        $this->assertSame(['7', '3', '1'], RsvpReminderBuckets::eligibleFor(1));
        $this->assertSame(['7', '3', '1'], RsvpReminderBuckets::eligibleFor(0));
        $this->assertNull(RsvpReminderBuckets::windowFor(9));
        $this->assertSame('7', RsvpReminderBuckets::windowFor(6));
        $this->assertSame('3', RsvpReminderBuckets::windowFor(2));
        $this->assertSame('1', RsvpReminderBuckets::windowFor(0));
    }

    public function test_nothing_is_sent_more_than_seven_days_before_the_deadline(): void
    {
        $guest = $this->guest();

        $this->runOn('2026-10-12'); // 8 days out

        $this->assertSame(0, $this->mailsFor($guest));
    }

    public function test_the_classic_7_3_1_cadence_still_sends_one_reminder_each_and_none_in_between(): void
    {
        $guest = $this->guest();

        foreach (['2026-10-13' => 1, '2026-10-14' => 1, '2026-10-16' => 1, '2026-10-17' => 2, '2026-10-19' => 3, '2026-10-20' => 3] as $date => $expected) {
            $this->runOn($date);
            $this->assertSame($expected, $this->mailsFor($guest), "after the run on $date");
        }

        $this->assertSame(['7', '3', '1'], $guest->fresh()->rsvp_reminders_sent);
    }

    // ── G7: catch-up ─────────────────────────────────────────────────────────

    public function test_a_deadline_set_five_days_out_is_still_reminded_once(): void
    {
        $guest = $this->guest();

        $this->runOn('2026-10-15'); // 5 days out: the old exact-day rule sent nothing
        $this->assertSame(1, $this->mailsFor($guest));
        $this->assertSame(5, $this->lastDays($guest));

        $this->runOn('2026-10-16'); // 4 days: same window, already used
        $this->assertSame(1, $this->mailsFor($guest));
    }

    public function test_a_deadline_two_days_out_consumes_both_crossed_windows_in_one_email(): void
    {
        $guest = $this->guest();

        $this->runOn('2026-10-18'); // 2 days out
        $this->assertSame(1, $this->mailsFor($guest), 'one email, not one per crossed window');
        $this->assertSame(['7', '3'], $guest->fresh()->rsvp_reminders_sent);

        $this->runOn('2026-10-19'); // 1 day: the last window is still to come
        $this->assertSame(2, $this->mailsFor($guest));
    }

    public function test_a_deadline_closing_today_is_reminded_with_today_wording_and_the_closing_time(): void
    {
        $guest = $this->guest();

        $this->runOn('2026-10-20'); // closes 18:00 today

        $this->assertSame(1, $this->mailsFor($guest));
        $this->assertSame(0, $this->lastDays($guest));

        $mail = (new RsvpReminderNotification($this->event->fresh(), $guest, 0))->toMail($guest);
        $text = implode("\n", $mail->introLines);
        $this->assertStringContainsString('is today.', $text);
        $this->assertStringContainsString('You can respond until Tuesday, October 20, 2026 at 6:00 PM CAT.', $text);
    }

    public function test_a_missed_scheduler_day_is_caught_up_on_the_next_run(): void
    {
        $guest = $this->guest();
        $this->runOn('2026-10-13'); // 7 days: sent
        // the 3-day run (17 Oct) never happened
        $this->runOn('2026-10-18'); // 2 days: the 3 window is overdue

        $this->assertSame(2, $this->mailsFor($guest));
        $this->assertSame(2, $this->lastDays($guest));
    }

    public function test_nothing_is_sent_once_the_deadline_has_passed(): void
    {
        $guest = $this->guest();

        Carbon::setTestNow(Carbon::parse('2026-10-20 18:30:00', config('events.timezone'))->utc());
        $this->artisan('rsvp:send-reminders')->assertSuccessful();

        $this->assertSame(0, $this->mailsFor($guest));
    }

    public function test_reminder_wording_uses_the_real_days_remaining(): void
    {
        $guest = $this->guest();
        $event = $this->event->fresh();

        $this->assertStringContainsString('tomorrow', implode("\n", (new RsvpReminderNotification($event, $guest, 1))->toMail($guest)->introLines));
        $this->assertStringContainsString('in 5 days', implode("\n", (new RsvpReminderNotification($event, $guest, 5))->toMail($guest)->introLines));
    }

    // ── G6: changing the deadline starts the cadence again ───────────────────

    public function test_moving_the_deadline_to_another_day_reminds_again(): void
    {
        $guest = $this->guest();
        $this->runOn('2026-10-13'); // 7 days before 20 Oct
        $this->assertSame(['7'], $guest->fresh()->rsvp_reminders_sent);

        $this->event->fresh()->update(['rsvp_deadline' => '2026-10-27 18:00:00']);
        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent, 'the old markers belong to the old deadline');

        $this->runOn('2026-10-20'); // 7 days before the NEW deadline: this was silently skipped
        $this->assertSame(2, $this->mailsFor($guest));
    }

    public function test_the_old_idempotency_key_does_not_block_the_new_deadline(): void
    {
        $guest = $this->guest();
        $this->runOn('2026-10-13');
        $firstKey = NotificationLog::query()->where('guest_id', $guest->id)->value('idempotency_key');

        $this->event->fresh()->update(['rsvp_deadline' => '2026-10-27 18:00:00']);
        $this->runOn('2026-10-20');

        $keys = NotificationLog::query()->where('guest_id', $guest->id)->pluck('idempotency_key')->all();
        $this->assertCount(2, array_unique($keys));
        $this->assertStringEndsWith(':20261020', $firstKey);
        $this->assertContains(sprintf('rsvp-reminder:%d:%d:7:20261027', $this->event->id, $guest->id), $keys);
    }

    public function test_changing_only_the_time_of_day_does_not_remind_again(): void
    {
        $guest = $this->guest();
        $this->runOn('2026-10-13');

        $this->event->fresh()->update(['rsvp_deadline' => '2026-10-20 21:00:00']);
        $this->assertSame(['7'], $guest->fresh()->rsvp_reminders_sent);

        $this->runOn('2026-10-13');
        $this->assertSame(1, $this->mailsFor($guest), 'a typo fix to the hour must not email everyone twice');
    }

    public function test_removing_the_deadline_stops_reminders_and_clears_the_markers(): void
    {
        $guest = $this->guest();
        $this->runOn('2026-10-13');

        $this->event->fresh()->update(['rsvp_deadline' => null]);
        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent);

        $this->runOn('2026-10-18');
        $this->assertSame(1, $this->mailsFor($guest), 'no deadline, no reminders');
    }

    public function test_adding_a_deadline_back_starts_a_fresh_cadence(): void
    {
        $guest = $this->guest();
        $this->runOn('2026-10-13');
        $this->event->fresh()->update(['rsvp_deadline' => null]);

        $this->event->fresh()->update(['rsvp_deadline' => '2026-10-20 18:00:00']);
        $this->runOn('2026-10-17');

        $this->assertSame(2, $this->mailsFor($guest));
    }

    // ── Who is reminded (unchanged rules) ────────────────────────────────────

    public function test_responders_and_guests_who_opted_out_are_not_reminded(): void
    {
        $answered = $this->guest();
        Rsvp::query()->create(['event_id' => $this->event->id, 'guest_id' => $answered->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
        $optedOut = $this->guest(['email_reminders_stopped_at' => now()]);
        $waiting = $this->guest();

        $this->runOn('2026-10-17');

        $this->assertSame(0, $this->mailsFor($answered));
        $this->assertSame(0, $this->mailsFor($optedOut));
        $this->assertSame(1, $this->mailsFor($waiting));
    }

    public function test_only_pro_plus_owners_get_automated_reminders(): void
    {
        $base = Event::factory()->for(User::factory())->published()->create([
            'is_public' => true, 'event_date' => '2026-11-20', 'event_time' => '15:00:00', 'rsvp_deadline' => '2026-10-20 18:00:00',
        ]);
        $guest = Guest::factory()->create(['event_id' => $base->id, 'email' => 'b@example.test', 'invitation_token' => null, 'rsvp_reminders_sent' => []]);

        $this->runOn('2026-10-17');

        $this->assertSame(0, $this->mailsFor($guest));
    }

    // ── The host's manual reminder uses the same keys ────────────────────────

    public function test_a_manual_bulk_reminder_can_be_sent_again_after_the_deadline_moves(): void
    {
        $owner = $this->event->user;
        $guest = $this->guest();
        $token = $owner->createToken('test')->plainTextToken;
        $send = fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'send_reminder_email', 'guest_ids' => [$guest->id], 'days_until' => 3])
            ->assertOk();

        $send();
        $this->assertSame(1, $this->mailsFor($guest));
        $this->assertSame(1, NotificationLog::query()->where('idempotency_key', sprintf('bulk-reminder:%d:%d:3:20261020', $this->event->id, $guest->id))->count());

        $this->event->fresh()->update(['rsvp_deadline' => '2026-10-27 18:00:00']);
        $send();

        $this->assertSame(2, $this->mailsFor($guest), 'the same days_until is allowed again for the new deadline');
    }
}
