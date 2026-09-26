<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\GuestEventReminderNotification;
use App\Services\CommunicationService;
use App\Support\EventReminderBuckets;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/guest-email-reminders.md Phase 2 — the email twin of the WhatsApp event reminder.
 */
class GuestEmailReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config()->set('communications.guest_email_reminders.enabled', true);
        // 07:00 UTC is 09:00 in Lusaka, when the scheduler runs it.
        Carbon::setTestNow(Carbon::parse('2026-12-05 07:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $eventOverrides
     * @return array{0: Event, 1: Guest}
     */
    private function seedAcceptedGuest(
        string $eventDate = '2026-12-12',
        string $email = 'guest@example.test',
        array $eventOverrides = [],
        ?User $owner = null,
        RsvpStatus|false $rsvp = RsvpStatus::Accepted,
    ): array {
        $owner ??= User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create($eventOverrides + [
            'event_date' => $eventDate,
            'event_time' => '14:00:00',
            'venue' => 'Ciela Resort',
            'name' => "Mary's wedding",
        ]);
        $guest = Guest::factory()->for($event)->create(['email' => $email]);

        if ($rsvp !== false) {
            Rsvp::factory()->for($guest)->create([
                'event_id' => $event->id,
                'status' => $rsvp,
                'attendee_count' => 1,
            ]);
        }

        return [$event, $guest];
    }

    private function run09(): void
    {
        $this->artisan('events:send-guest-email-reminders')->assertSuccessful();
    }

    private function assertReminderSentTo(string $email, string $bucket): void
    {
        Notification::assertSentOnDemand(
            GuestEventReminderNotification::class,
            fn (GuestEventReminderNotification $n, array $channels, AnonymousNotifiable $notifiable) => ($notifiable->routes['mail'] ?? null) === $email
                && $n->bucket === $bucket
        );
    }

    // ── Who gets one, and when ───────────────────────────────────────────────────────

    public function test_sends_the_seven_one_and_zero_day_reminders(): void
    {
        [, $seven] = $this->seedAcceptedGuest('2026-12-12', 'seven@example.test');
        $this->run09();
        $this->assertReminderSentTo('seven@example.test', '7');

        Carbon::setTestNow(Carbon::parse('2026-12-11 07:00:00'));
        $this->run09();
        $this->assertReminderSentTo('seven@example.test', '1');

        Carbon::setTestNow(Carbon::parse('2026-12-12 07:00:00'));
        $this->run09();
        $this->assertReminderSentTo('seven@example.test', '0');

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 3);
    }

    public function test_sends_on_no_other_day(): void
    {
        $this->seedAcceptedGuest('2026-12-12');

        foreach (['2026-12-04', '2026-12-06', '2026-12-08', '2026-12-10', '2026-12-13'] as $day) {
            Carbon::setTestNow(Carbon::parse($day.' 07:00:00'));
            $this->run09();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_only_accepted_guests_with_an_email_are_reminded(): void
    {
        [$event] = $this->seedAcceptedGuest('2026-12-12', 'accepted@example.test');

        foreach ([RsvpStatus::Declined, RsvpStatus::Maybe] as $i => $status) {
            $guest = Guest::factory()->for($event)->create(['email' => "other{$i}@example.test"]);
            Rsvp::factory()->for($guest)->create(['event_id' => $event->id, 'status' => $status, 'attendee_count' => 1]);
        }
        Guest::factory()->for($event)->create(['email' => 'noreply@example.test']); // never answered
        foreach (['', null] as $i => $blank) {
            $guest = Guest::factory()->for($event)->create(['email' => $blank]);
            Rsvp::factory()->for($guest)->create(['event_id' => $event->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
        }

        $this->run09();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
        $this->assertReminderSentTo('accepted@example.test', '7');
    }

    public function test_cancelled_deleted_draft_and_ticketed_events_are_not_reminded(): void
    {
        [$cancelled] = $this->seedAcceptedGuest('2026-12-12', 'cancelled@example.test');
        $cancelled->forceFill(['cancelled_at' => now()])->save();

        [$deleted] = $this->seedAcceptedGuest('2026-12-12', 'deleted@example.test');
        $deleted->delete();

        [$draft] = $this->seedAcceptedGuest('2026-12-12', 'draft@example.test');
        $draft->forceFill(['is_published' => false])->save();

        $owner = User::factory()->proPlus()->create();
        $ticketed = Event::factory()->for($owner)->ticketed()->create(['event_date' => '2026-12-12', 'is_published' => true]);
        $guest = Guest::factory()->for($ticketed)->create(['email' => 'ticketed@example.test']);
        Rsvp::factory()->for($guest)->create(['event_id' => $ticketed->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);

        $this->run09();

        Notification::assertNothingSent();
    }

    public function test_a_paused_invitation_is_still_reminded(): void
    {
        [$event] = $this->seedAcceptedGuest('2026-12-12', 'paused@example.test');
        $event->forceFill(['invitation_paused_at' => now()])->save();

        $this->run09();

        $this->assertReminderSentTo('paused@example.test', '7');
    }

    public function test_only_pro_plus_hosts_send_reminders(): void
    {
        $this->seedAcceptedGuest('2026-12-12', 'base@example.test', owner: User::factory()->create());
        $this->seedAcceptedGuest('2026-12-12', 'pro@example.test', owner: User::factory()->pro()->create());

        $this->run09();

        Notification::assertNothingSent();

        $this->seedAcceptedGuest('2026-12-12', 'plus@example.test', owner: User::factory()->proPlus()->create());
        $this->run09();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
        $this->assertReminderSentTo('plus@example.test', '7');
    }

    public function test_the_flag_off_sends_nothing(): void
    {
        config()->set('communications.guest_email_reminders.enabled', false);
        $this->seedAcceptedGuest('2026-12-12');

        $this->artisan('events:send-guest-email-reminders')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_the_flag_ships_off_and_the_command_is_scheduled(): void
    {
        $this->assertStringContainsString('COMM_GUEST_EMAIL_REMINDERS_ENABLED=false', (string) file_get_contents(base_path('.env.example')));

        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'events:send-guest-email-reminders'));

        $this->assertTrue($scheduled, 'events:send-guest-email-reminders must be on the schedule');
    }

    public function test_the_sender_enforces_every_rule_itself_not_just_the_command(): void
    {
        $service = app(CommunicationService::class);

        [$event, $guest] = $this->seedAcceptedGuest('2026-12-12', 'rules@example.test');
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail($event, $guest, '3'), 'unknown bucket');

        [, $declined] = $this->seedAcceptedGuest('2026-12-12', 'declined@example.test', rsvp: RsvpStatus::Declined);
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail($declined->event, $declined, '7'));

        [, $unanswered] = $this->seedAcceptedGuest('2026-12-12', 'unanswered@example.test', rsvp: false);
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail($unanswered->event, $unanswered, '7'));

        [$noEmailEvent, $noEmail] = $this->seedAcceptedGuest('2026-12-12', 'x@example.test');
        $noEmail->forceFill(['email' => ''])->save();
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail($noEmailEvent, $noEmail, '7'));

        [$cancelled, $cancelledGuest] = $this->seedAcceptedGuest('2026-12-12', 'cancelled@example.test');
        $cancelled->forceFill(['cancelled_at' => now()])->save();
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail($cancelled->fresh(), $cancelledGuest, '7'));

        [$deleted, $deletedGuest] = $this->seedAcceptedGuest('2026-12-12', 'deleted@example.test');
        $deleted->delete();
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail(Event::withTrashed()->find($deleted->id), $deletedGuest, '7'));

        [$base, $baseGuest] = $this->seedAcceptedGuest('2026-12-12', 'base@example.test', owner: User::factory()->create());
        $this->assertSame('disabled', $service->sendGuestEventReminderEmail($base, $baseGuest, '7'));

        config()->set('communications.guest_email_reminders.enabled', false);
        $this->assertSame('disabled', $service->sendGuestEventReminderEmail($event, $guest, '7'));

        Notification::assertNothingSent();
    }

    // ── Once, and again when it should be ────────────────────────────────────────────

    public function test_each_bucket_is_sent_once_however_often_the_command_runs(): void
    {
        $this->seedAcceptedGuest('2026-12-12');

        $this->run09();
        $this->run09();
        $this->run09();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
        $this->assertSame(1, NotificationLog::query()->where('type', 'guest_event_reminder_email')->count());
    }

    public function test_a_failed_attempt_is_retried_on_its_own_row_but_a_queued_or_sent_one_is_not(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest('2026-12-12');
        $key = 'email-event-reminder:'.$event->id.':'.$guest->id.':7:2026-12-12';
        $service = app(CommunicationService::class);

        NotificationLog::query()->create([
            'event_id' => $event->id, 'guest_id' => $guest->id, 'channel' => 'email',
            'type' => 'guest_event_reminder_email', 'status' => NotificationLog::STATUS_FAILED,
            'idempotency_key' => $key, 'response' => 'queue was down',
        ]);

        $this->assertSame('sent', $service->sendGuestEventReminderEmail($event, $guest, '7'));
        $this->assertSame('skipped', $service->sendGuestEventReminderEmail($event, $guest, '7'));

        $log = NotificationLog::query()->where('idempotency_key', $key)->sole();
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertNull($log->response);
        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
    }

    public function test_a_pending_attempt_blocks_a_second_one(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest('2026-12-12');
        NotificationLog::query()->create([
            'event_id' => $event->id, 'guest_id' => $guest->id, 'channel' => 'email',
            'type' => 'guest_event_reminder_email', 'status' => NotificationLog::STATUS_PENDING,
            'idempotency_key' => 'email-event-reminder:'.$event->id.':'.$guest->id.':7:2026-12-12',
        ]);

        $this->assertSame('skipped', app(CommunicationService::class)->sendGuestEventReminderEmail($event, $guest, '7'));
        Notification::assertNothingSent();
    }

    public function test_a_moved_event_is_reminded_again_for_its_new_date(): void
    {
        [$event] = $this->seedAcceptedGuest('2026-12-12');
        $this->run09();
        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);

        $event->update(['event_date' => '2026-12-13']);
        Carbon::setTestNow(Carbon::parse('2026-12-06 07:00:00'));
        $this->run09();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 2);
        $this->assertDatabaseHas('notification_logs', ['idempotency_key' => 'email-event-reminder:'.$event->id.':1:7:2026-12-13']);
    }

    // ── The day-of edge ──────────────────────────────────────────────────────────────

    public function test_the_day_of_reminder_is_skipped_once_the_event_has_started(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-12 07:00:00')); // 09:00 Lusaka
        [$early] = $this->seedAcceptedGuest('2026-12-12', 'early@example.test', ['event_time' => '08:00:00']);
        [$later] = $this->seedAcceptedGuest('2026-12-12', 'later@example.test', ['event_time' => '14:00:00']);

        $this->run09();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
        $this->assertReminderSentTo('later@example.test', '0');
        $this->assertSame(0, NotificationLog::query()->where('event_id', $early->id)->count());
    }

    public function test_an_event_with_no_start_time_still_gets_its_day_of_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-12 07:00:00'));
        [$event, $guest] = $this->seedAcceptedGuest('2026-12-12', 'notime@example.test');
        $event->event_time = ''; // in memory: hasStartTime() is false, so there is nothing to be late for

        $this->assertSame('sent', app(CommunicationService::class)->sendGuestEventReminderEmail($event, $guest, '0'));
    }

    // ── Volume ───────────────────────────────────────────────────────────────────────

    public function test_the_hourly_cap_stops_the_run_and_the_next_hour_finishes_it(): void
    {
        config()->set('communications.reminder_hourly_cap_per_event', 2);
        [$event] = $this->seedAcceptedGuest('2026-12-12', 'g1@example.test');
        foreach (['g2', 'g3', 'g4'] as $name) {
            $guest = Guest::factory()->for($event)->create(['email' => "{$name}@example.test"]);
            Rsvp::factory()->for($guest)->create(['event_id' => $event->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
        }

        $this->run09();
        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 2);

        $this->run09(); // same hour: nothing more
        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 2);

        Carbon::setTestNow(now()->addMinutes(61));
        $this->run09();
        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 4);
    }

    // ── Bookkeeping ──────────────────────────────────────────────────────────────────

    public function test_the_log_row_records_channel_type_bucket_and_status(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();

        $this->run09();

        $log = NotificationLog::query()->where('guest_id', $guest->id)->sole();
        $this->assertSame($event->id, $log->event_id);
        $this->assertSame('email', $log->channel);
        $this->assertSame('guest_event_reminder_email', $log->type);
        $this->assertSame(NotificationLog::STATUS_SENT, $log->status);
        $this->assertSame('7', $log->meta['bucket']);
    }

    public function test_it_neither_uses_nor_disturbs_the_whatsapp_markers(): void
    {
        [, $guest] = $this->seedAcceptedGuest();

        $this->run09();

        $this->assertSame([], $guest->fresh()->whatsapp_event_reminders_sent);
        $this->assertSame(0, NotificationLog::query()->where('channel', 'whatsapp')->count());
    }

    // ── The email itself ─────────────────────────────────────────────────────────────

    private function mailFor(Event $event, Guest $guest, string $bucket): MailMessage
    {
        return (new GuestEventReminderNotification($event, $guest->load('rsvp'), $bucket))->toMail(new AnonymousNotifiable);
    }

    private function bodyOf(MailMessage $mail): string
    {
        return implode("\n", $mail->introLines).implode("\n", $mail->outroLines);
    }

    public function test_the_subject_and_lead_follow_the_bucket_and_match_whatsapp(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();

        foreach (['7' => 'One week to go: ', '1' => 'Tomorrow: ', '0' => 'Today: '] as $bucket => $prefix) {
            $mail = $this->mailFor($event, $guest, $bucket);

            $this->assertSame($prefix.$event->name, $mail->subject);
            $this->assertContains(EventReminderBuckets::lead($event->name, $bucket), $mail->introLines);
        }
    }

    public function test_it_states_the_date_time_and_venue(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest('2026-12-12');

        $body = $this->bodyOf($this->mailFor($event, $guest, '7'));

        $this->assertStringContainsString('**Date:** 12 December 2026', $body);
        $this->assertStringContainsString('**Time:** 14:00', $body);
        $this->assertStringContainsString('**Venue:** Ciela Resort', $body);
    }

    public function test_the_map_link_appears_only_when_the_event_has_coordinates(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();

        $event->forceFill(['latitude' => null, 'longitude' => null]);
        $this->assertStringNotContainsString('google.com/maps', $this->bodyOf($this->mailFor($event, $guest, '7')));

        $event->forceFill(['latitude' => -15.4167, 'longitude' => 28.2833]);
        $this->assertStringContainsString('google.com/maps?q=-15.4167,28.2833', $this->bodyOf($this->mailFor($event, $guest, '7')));
    }

    public function test_the_button_is_the_pass_when_the_guest_has_one(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();

        $mail = $this->mailFor($event, $guest, '1');

        $this->assertSame('View your pass', $mail->actionText);
        $this->assertSame($guest->passPageUrl(), $mail->actionUrl);
    }

    public function test_the_button_is_the_invitation_when_there_is_no_pass(): void
    {
        // A Base host has no check-in tools, so there is no pass to show.
        [$event, $guest] = $this->seedAcceptedGuest(owner: User::factory()->create());

        $withToken = $this->mailFor($event, $guest, '1');
        $this->assertSame('View invitation details', $withToken->actionText);
        $this->assertSame($guest->personalRsvpUrl(), $withToken->actionUrl);

        $guest->forceFill(['invitation_token' => null]);
        $withoutToken = $this->mailFor($event, $guest, '1');
        $this->assertSame('View invitation details', $withoutToken->actionText);
        $this->assertSame(route('events.public', ['slug' => $event->slug], absolute: true), $withoutToken->actionUrl);
    }

    public function test_it_offers_to_change_the_response_only_while_rsvp_is_open(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();

        $this->assertStringContainsString('update your response', $this->bodyOf($this->mailFor($event, $guest, '7')));

        $event->forceFill(['rsvp_deadline' => now()->subDay()]);
        $this->assertStringNotContainsString('update your response', $this->bodyOf($this->mailFor($event, $guest, '7')));
    }

    public function test_it_carries_no_attachments_and_says_why_the_guest_got_it(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();

        $mail = $this->mailFor($event, $guest, '7');

        $this->assertSame([], $mail->attachments);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertStringContainsString('you accepted the invitation to '.$event->name, $this->bodyOf($mail));
    }

    public function test_it_is_queued_on_the_default_queue(): void
    {
        [$event, $guest] = $this->seedAcceptedGuest();
        $notification = new GuestEventReminderNotification($event, $guest, '7');

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame('default', $notification->queue);
        $this->assertSame(['mail'], $notification->via(new AnonymousNotifiable));
    }
}
