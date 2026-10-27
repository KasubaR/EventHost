<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\GuestEventReminderNotification;
use App\Services\CommunicationService;
use App\Support\EventReminderBuckets;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/email-gaps.md Phase 1: a queued guest email is checked again when the worker sends it. Each test queues through
 * CommunicationService on the database queue, changes something, then runs the worker.
 */
class EmailSendTimeChecksTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    private CommunicationService $comms;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', config('events.timezone'))->utc());
        config(['queue.default' => 'database']);

        $this->host = User::factory()->proPlus()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-11-10 18:00:00',
        ]);
        $this->comms = app(CommunicationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guest(array $attributes = []): Guest
    {
        return Guest::factory()->for($this->event)->create(array_merge(['email' => 'guest@example.test'], $attributes));
    }

    private function acceptedGuest(): Guest
    {
        $guest = $this->guest();
        Rsvp::factory()->forGuest($guest)->accepted()->create();

        return $guest->fresh();
    }

    private function queueReminder(Guest $guest): NotificationLog
    {
        $this->comms->sendRsvpReminder($this->event, $guest, 3, 'test-reminder:'.$guest->id);

        return NotificationLog::query()->where('guest_id', $guest->id)->latest('id')->firstOrFail();
    }

    private function work(): void
    {
        // A worker stops after one job once the process passes --memory (128 MB), which the full suite already has.
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default', '--memory' => 4096]);
    }

    /** @return array<int, mixed> */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    private function assertSkipped(NotificationLog $log, string $reason): void
    {
        $log->refresh();
        $this->assertCount(0, $this->sent(), 'nothing should be emailed');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'a skip is not a failed job');
        $this->assertSame(NotificationLog::DELIVERY_SUPERSEDED, $log->delivery_status);
        $this->assertSame($reason, $log->meta['skipped'] ?? null);
    }

    public function test_a_live_reminder_is_sent_and_recorded_as_accepted(): void
    {
        $log = $this->queueReminder($this->guest());
        $this->work();

        $this->assertCount(1, $this->sent());
        $this->assertSame(NotificationLog::DELIVERY_ACCEPTED, $log->fresh()->delivery_status);
    }

    public function test_an_event_deleted_after_queuing_sends_nothing(): void
    {
        $log = $this->queueReminder($this->guest());
        $this->event->delete();
        $this->work();

        $this->assertSkipped($log, 'event_deleted');
    }

    public function test_a_purged_event_does_not_land_in_failed_jobs(): void
    {
        $this->queueReminder($this->guest());
        $this->event->forceDelete();
        $this->work();

        $this->assertCount(0, $this->sent());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_cancelled_event_drops_a_queued_event_reminder(): void
    {
        $guest = $this->acceptedGuest();
        $log = NotificationLog::query()->create([
            'event_id' => $this->event->id, 'guest_id' => $guest->id, 'channel' => 'email',
            'type' => 'guest_event_reminder', 'status' => NotificationLog::STATUS_SENT,
        ]);
        Notification::route('mail', $guest->email)
            ->notify((new GuestEventReminderNotification($this->event, $guest, EventReminderBuckets::BUCKET_7))->withLog($log));

        $this->event->forceFill(['cancelled_at' => now()])->save();
        $this->work();

        $this->assertSkipped($log, 'cancelled');
    }

    public function test_a_guest_who_answers_while_the_reminder_waits_is_not_reminded(): void
    {
        $guest = $this->guest();
        $log = $this->queueReminder($guest);
        Rsvp::factory()->forGuest($guest)->declined()->create();
        $this->work();

        $this->assertSkipped($log, 'answered');
    }

    public function test_a_guest_who_stops_reminders_while_one_waits_is_not_reminded(): void
    {
        $guest = $this->guest();
        $log = $this->queueReminder($guest);
        $guest->forceFill(['email_reminders_stopped_at' => now()])->save();
        $this->work();

        $this->assertSkipped($log, 'reminders_stopped');
    }

    public function test_a_changed_address_never_gets_mail_meant_for_the_old_one(): void
    {
        $guest = $this->guest();
        $log = $this->queueReminder($guest);
        $guest->forceFill(['email' => 'new@example.test'])->save();
        $this->work();

        $this->assertSkipped($log, 'address_changed');
    }

    public function test_a_moved_event_drops_the_reminder_for_the_old_date(): void
    {
        $guest = $this->acceptedGuest();
        $log = NotificationLog::query()->create([
            'event_id' => $this->event->id, 'guest_id' => $guest->id, 'channel' => 'email',
            'type' => 'guest_event_reminder', 'status' => NotificationLog::STATUS_SENT,
        ]);
        Notification::route('mail', $guest->email)
            ->notify((new GuestEventReminderNotification($this->event, $guest, EventReminderBuckets::BUCKET_7))->withLog($log));

        $this->event->update(['event_date' => '2026-11-27']);
        $this->work();

        $this->assertSkipped($log, 'event_moved');
    }

    public function test_an_email_still_queued_a_day_later_is_dropped_not_sent_late(): void
    {
        $log = $this->queueReminder($this->guest());
        Carbon::setTestNow(now()->addHours(25));
        $this->work();

        $this->assertSkipped($log, 'expired');
    }

    public function test_a_reminder_after_the_deadline_passed_is_dropped(): void
    {
        $log = $this->queueReminder($this->guest());
        $this->event->update(['rsvp_deadline' => '2026-10-01 18:00:00']);
        Carbon::setTestNow(Carbon::parse('2026-10-01 19:00:00', config('events.timezone'))->utc());
        $this->work();

        $this->assertSkipped($log, 'rsvp_closed');
    }

    public function test_a_moved_deadline_is_counted_again_when_sent(): void
    {
        $this->queueReminder($this->guest());
        $this->event->update(['rsvp_deadline' => '2026-10-02 18:00:00']);
        $this->work();

        $this->assertCount(1, $sent = $this->sent());
        $this->assertStringContainsString('is tomorrow', $sent[0]->toString());
    }

    public function test_accept_then_decline_sends_one_confirmation_not_two(): void
    {
        $guest = $this->guest();
        $rsvp = Rsvp::factory()->forGuest($guest)->accepted()->create();
        $this->comms->sendRsvpConfirmation($this->event, $guest, $rsvp);
        $first = NotificationLog::query()->where('guest_id', $guest->id)->latest('id')->firstOrFail();

        $rsvp->update(['status' => 'declined', 'attendee_count' => 0]);
        $this->comms->sendRsvpConfirmation($this->event, $guest, $rsvp->fresh());
        $this->work();

        $this->assertCount(1, $this->sent());
        $this->assertSame('answer_changed', $first->fresh()->meta['skipped'] ?? null);
    }
}
