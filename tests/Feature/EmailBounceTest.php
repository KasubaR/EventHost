<?php

namespace Tests\Feature;

use App\Mail\Bounces\BounceMailbox;
use App\Models\EmailSuppression;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestRemoval;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\RsvpReminderNotification;
use App\Services\CommunicationService;
use App\Support\BounceReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/email-gaps.md Phase 3: bounce reports from the bounce mailbox, the list of addresses that hard-bounced, and what the
 * host sees and can do about it.
 */
class EmailBounceTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    /** Messages the fake mailbox hands out, and whether the command asked to move them. */
    private array $inbox = [];

    private ?bool $moved = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', config('events.timezone'))->utc());
        config(['mail.bounce_imap.host' => 'imap.eventhost.test', 'mail.bounce_imap.username' => 'bounces@eventhost.test']);

        $this->host = User::factory()->proPlus()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-11-10 18:00:00',
        ]);

        $this->app->instance(BounceMailbox::class, new class($this) implements BounceMailbox
        {
            public function __construct(private EmailBounceTest $test) {}

            public function process(callable $handle, int $limit, bool $move = true): int
            {
                return $this->test->drainInbox($handle, $move);
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function drainInbox(callable $handle, bool $move): int
    {
        $this->moved = $move;
        foreach ($this->inbox as $raw) {
            $handle($raw);
        }

        return count($this->inbox);
    }

    private function fixture(string $name, ?NotificationLog $log = null): string
    {
        $raw = (string) file_get_contents(base_path('tests/fixtures/bounces/'.$name.'.eml'));

        return str_replace('__REF__', $log !== null ? NotificationLog::mailRef($log->id) : 'none', $raw);
    }

    private function sentLog(Guest $guest, string $type = 'rsvp_reminder'): NotificationLog
    {
        return NotificationLog::query()->create([
            'event_id' => $this->event->id,
            'guest_id' => $guest->id,
            'channel' => 'email',
            'type' => $type,
            'status' => NotificationLog::STATUS_SENT,
            'delivery_status' => NotificationLog::DELIVERY_ACCEPTED,
            'sent_at' => now(),
        ]);
    }

    private function guest(string $email): Guest
    {
        return Guest::factory()->for($this->event)->create(['email' => $email]);
    }

    // ---------------------------------------------------------------- parser

    public function test_a_gmail_bounce_is_read(): void
    {
        $report = BounceReport::parse($this->fixture('gmail'));

        $this->assertNotNull($report);
        $this->assertSame('nobody.here@gmail.com', $report->recipient);
        $this->assertSame('5.1.1', $report->status);
        $this->assertTrue($report->isHard());
        $this->assertSame('none', $report->ref);
        $this->assertStringNotContainsString('@', (string) $report->diagnostic, 'the diagnostic must not keep the address');
    }

    public function test_an_exim_bounce_takes_the_real_code_from_the_diagnostic(): void
    {
        $report = BounceReport::parse($this->fixture('exim'));

        $this->assertSame('gone@example.org', $report?->recipient);
        $this->assertSame('5.1.1', $report->status);
        $this->assertTrue($report->isHard());
    }

    public function test_an_outlook_bounce_is_read_without_a_reference(): void
    {
        $report = BounceReport::parse($this->fixture('outlook'));

        $this->assertSame('missing.person@outlook.com', $report?->recipient);
        $this->assertSame('5.1.10', $report->status);
        $this->assertTrue($report->isHard());
        $this->assertNull($report->ref);
    }

    public function test_a_delay_notice_is_soft(): void
    {
        $report = BounceReport::parse($this->fixture('exim-delayed'));

        $this->assertNotNull($report);
        $this->assertFalse($report->isHard());
    }

    public function test_an_auto_reply_is_not_a_bounce(): void
    {
        $this->assertNull(BounceReport::parse($this->fixture('out-of-office')));
    }

    public function test_a_full_mailbox_and_a_policy_refusal_do_not_suppress_the_address(): void
    {
        foreach (['5.2.2', '5.7.1'] as $code) {
            $raw = "Content-Type: message/delivery-status\n\nFinal-Recipient: rfc822; a@example.org\nAction: failed\nStatus: {$code}\n";
            $this->assertFalse(BounceReport::parse($raw)?->isHard(), $code);
        }
    }

    // ---------------------------------------------------------------- mail:process-bounces

    public function test_a_hard_bounce_marks_the_email_and_stops_mail_to_the_address(): void
    {
        $guest = $this->guest('nobody.here@gmail.com');
        $log = $this->sentLog($guest);
        $this->inbox = [$this->fixture('gmail', $log)];

        Artisan::call('mail:process-bounces');
        $output = Artisan::output();

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_BOUNCED, $log->delivery_status);
        $this->assertSame('5.1.1', $log->error_code);
        $this->assertTrue(EmailSuppression::isSuppressed('Nobody.Here@gmail.com'));
        $this->assertSame($log->id, EmailSuppression::forEmail('nobody.here@gmail.com')?->notification_log_id);
        $this->assertTrue($this->moved);
        $this->assertStringContainsString('Stopped email to 1', $output);
        $this->assertStringNotContainsString('nobody.here', $output, 'the command never prints an address');
    }

    public function test_a_bounce_without_a_reference_is_matched_by_address(): void
    {
        $guest = $this->guest('Missing.Person@outlook.com');
        $log = $this->sentLog($guest);
        $this->inbox = [$this->fixture('outlook')];

        Artisan::call('mail:process-bounces');

        $this->assertSame(NotificationLog::DELIVERY_BOUNCED, $log->fresh()->delivery_status);
        $this->assertTrue(EmailSuppression::isSuppressed('missing.person@outlook.com'));
    }

    public function test_a_bounce_for_an_address_we_never_wrote_to_changes_nothing(): void
    {
        $this->inbox = [$this->fixture('exim')];

        Artisan::call('mail:process-bounces');

        $this->assertSame(0, EmailSuppression::query()->count());
        $this->assertStringContainsString('Not matched to an email we sent: 1', Artisan::output());
    }

    public function test_a_reference_for_another_address_is_not_trusted(): void
    {
        $log = $this->sentLog($this->guest('someone.else@example.org'));
        $this->inbox = [$this->fixture('exim', $log)];

        Artisan::call('mail:process-bounces');

        $this->assertSame(0, EmailSuppression::query()->count());
        $this->assertSame(NotificationLog::DELIVERY_ACCEPTED, $log->fresh()->delivery_status);
    }

    public function test_a_soft_bounce_only_notes_the_log(): void
    {
        $log = $this->sentLog($this->guest('slow@example.org'));
        $this->inbox = [$this->fixture('exim-delayed', $log)];

        Artisan::call('mail:process-bounces');

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_ACCEPTED, $log->delivery_status);
        $this->assertSame('4.4.1', $log->meta['bounce']['status'] ?? null);
        $this->assertSame(0, EmailSuppression::query()->count());
    }

    public function test_a_dry_run_changes_and_moves_nothing(): void
    {
        $log = $this->sentLog($this->guest('nobody.here@gmail.com'));
        $this->inbox = [$this->fixture('gmail', $log)];

        Artisan::call('mail:process-bounces', ['--dry-run' => true]);

        $this->assertFalse($this->moved);
        $this->assertSame(0, EmailSuppression::query()->count());
        $this->assertSame(NotificationLog::DELIVERY_ACCEPTED, $log->fresh()->delivery_status);
        $this->assertStringContainsString('Hard bounces: 1', Artisan::output());
    }

    public function test_an_unconfigured_mailbox_is_not_an_error(): void
    {
        config(['mail.bounce_imap.host' => null]);

        $this->assertSame(0, Artisan::call('mail:process-bounces'));
        $this->assertStringContainsString('not configured', Artisan::output());
    }

    public function test_an_address_with_no_bounce_for_a_year_comes_off_the_list(): void
    {
        $this->suppress('old@example.org', now()->subDays(EmailSuppression::KEEP_DAYS + 1));
        $this->suppress('recent@example.org', now()->subDays(10));

        Artisan::call('mail:process-bounces');

        $this->assertFalse(EmailSuppression::isSuppressed('old@example.org'));
        $this->assertTrue(EmailSuppression::isSuppressed('recent@example.org'));
    }

    // ---------------------------------------------------------------- what a suppression stops

    private function suppress(string $email, ?Carbon $at = null): EmailSuppression
    {
        return EmailSuppression::query()->create([
            'email' => $email,
            'reason' => EmailSuppression::REASON_HARD_BOUNCE,
            'status_code' => '5.1.1',
            'first_bounced_at' => $at ?? now(),
            'last_bounced_at' => $at ?? now(),
        ]);
    }

    public function test_a_suppressed_address_is_not_emailed_by_any_event(): void
    {
        Notification::fake();
        $this->suppress('dead@example.org');
        $otherEvent = Event::factory()->for(User::factory()->proPlus())->published()->create(['rsvp_deadline' => '2026-11-10 18:00:00']);
        $guest = Guest::factory()->for($otherEvent)->create(['email' => 'Dead@Example.org']);

        app(CommunicationService::class)->sendRsvpReminder($otherEvent, $guest, 3);

        Notification::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_an_email_already_queued_is_dropped_once_the_address_bounces(): void
    {
        $guest = $this->guest('dead@example.org');
        $log = $this->sentLog($guest);
        $log->forceFill(['delivery_status' => null])->save();
        $notification = (new RsvpReminderNotification($this->event, $guest, 3))->withLog($log);
        $this->suppress('dead@example.org');

        $this->assertFalse($notification->shouldSend(Notification::route('mail', 'dead@example.org'), 'mail'));
        $this->assertSame('suppressed', $log->fresh()->meta['skipped'] ?? null);
    }

    public function test_bulk_reminders_skip_a_bounced_address_and_say_why(): void
    {
        Notification::fake();
        $live = $this->guest('live@example.org');
        $dead = $this->guest('dead@example.org');
        $this->suppress('dead@example.org');

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), [
                'action' => 'send_reminder_email',
                'guest_ids' => [$live->id, $dead->id],
                'days_until' => 3,
            ])
            ->assertSessionHas('bulk_count', 1)
            ->assertSessionHas('bulk_skipped', 1)
            ->assertSessionHas('bulk_skip_reason', '1 email bounced');

        $this->assertSame([], $dead->fresh()->rsvp_reminders_sent, 'the reminder stays unused for when the address is fixed');
    }

    public function test_tell_them_refuses_a_bounced_address(): void
    {
        Notification::fake();
        $guest = $this->guest('dead@example.org');
        $guest->forceFill(['plus_one_allowed' => true])->save();
        $this->event->update(['allow_plus_one' => true]);
        $this->suppress('dead@example.org');

        $this->actingAs($this->host)
            ->post(route('events.guests.notify-plus-one', [$this->event, $guest]))
            ->assertSessionHasErrors('plus_one');

        $removal = GuestRemoval::query()->forceCreate([
            'event_id' => $this->event->id, 'name' => 'Gone', 'email' => 'dead@example.org',
            'invitation_sent' => true, 'removed_at' => now(), 'token_hash' => hash('sha256', 'x'),
        ]);
        $this->actingAs($this->host)
            ->post(route('events.guests.removed.notify', [$this->event, $removal]))
            ->assertSessionHasErrors('removed');

        Notification::assertNothingSent();
    }

    // ---------------------------------------------------------------- what the host sees

    public function test_the_guest_list_says_the_email_bounced_and_offers_a_retry(): void
    {
        $guest = $this->guest('dead@example.org');
        $this->suppress('dead@example.org');

        $this->actingAs($this->host)
            ->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Email bounced: The address does not exist. Check the address.')
            ->assertSee(route('events.guests.email-retry', [$this->event, $guest]), false);
    }

    public function test_the_guest_list_says_when_an_email_could_not_be_sent(): void
    {
        $guest = $this->guest('guest@example.org');
        NotificationLog::query()->create([
            'event_id' => $this->event->id, 'guest_id' => $guest->id, 'channel' => 'email', 'type' => 'rsvp_confirmation',
            'status' => NotificationLog::STATUS_FAILED, 'delivery_status' => NotificationLog::DELIVERY_FAILED, 'error_code' => 'transport',
        ]);

        $this->actingAs($this->host)
            ->get(route('events.guests.index', $this->event))
            ->assertSee("Email not delivered: The mail server couldn't be reached")
            ->assertDontSee(route('events.guests.email-retry', [$this->event, $guest]), false);
    }

    public function test_a_later_email_that_went_through_clears_an_earlier_failure(): void
    {
        $guest = $this->guest('guest@example.org');
        NotificationLog::query()->create([
            'event_id' => $this->event->id, 'guest_id' => $guest->id, 'channel' => 'email', 'type' => 'rsvp_confirmation',
            'status' => NotificationLog::STATUS_FAILED, 'delivery_status' => NotificationLog::DELIVERY_FAILED, 'error_code' => 'transport',
        ]);
        $this->sentLog($guest);

        $this->actingAs($this->host)
            ->get(route('events.guests.index', $this->event))
            ->assertDontSee('Email not delivered');
    }

    public function test_try_again_takes_the_address_off_the_list(): void
    {
        $guest = $this->guest('dead@example.org');
        $this->suppress('dead@example.org');

        $this->actingAs($this->host)
            ->post(route('events.guests.email-retry', [$this->event, $guest]))
            ->assertRedirect(route('events.guests.index', $this->event))
            ->assertSessionHas('status', 'email-retry');

        $this->assertFalse(EmailSuppression::isSuppressed('dead@example.org'));
    }

    public function test_only_the_owner_can_try_an_address_again(): void
    {
        $guest = $this->guest('dead@example.org');
        $this->suppress('dead@example.org');

        $this->actingAs(User::factory()->create())
            ->post(route('events.guests.email-retry', [$this->event, $guest]))
            ->assertForbidden();

        $this->assertTrue(EmailSuppression::isSuppressed('dead@example.org'));
    }

    public function test_changing_the_address_is_enough_to_email_the_guest_again(): void
    {
        Notification::fake();
        $guest = $this->guest('dead@example.org');
        $this->suppress('dead@example.org');
        $guest->forceFill(['email' => 'fixed@example.org'])->save();

        app(CommunicationService::class)->sendRsvpReminder($this->event, $guest->fresh(), 3);

        Notification::assertSentOnDemand(RsvpReminderNotification::class);
    }

    public function test_the_api_reports_email_delivery(): void
    {
        $guest = $this->guest('dead@example.org');
        $this->guest('fine@example.org');
        $this->suppress('dead@example.org');
        Sanctum::actingAs($this->host);

        $guests = collect($this->getJson("/api/v1/host/events/{$this->event->id}/guests")->assertOk()->json('guests.data'));

        $this->assertSame(
            ['kind' => 'bounced', 'reason' => 'The address does not exist', 'suppressed' => true],
            $guests->firstWhere('id', $guest->id)['email_delivery'],
        );
        $this->assertNull($guests->firstWhere('email', 'fine@example.org')['email_delivery']);
    }
}
