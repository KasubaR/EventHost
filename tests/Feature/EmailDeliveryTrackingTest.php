<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\RsvpReminderNotification;
use App\Rules\GuestEmail;
use App\Services\CommunicationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * plans/email-gaps.md Phase 2: what happened to a guest email after it was queued (accepted, failed and why), the retry
 * policy, the bounce address on every message, and the stricter guest email rule.
 */
class EmailDeliveryTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', config('events.timezone'))->utc());

        $this->host = User::factory()->proPlus()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-11-10 18:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function remind(Guest $guest): NotificationLog
    {
        app(CommunicationService::class)->sendRsvpReminder($this->event, $guest, 3, 'tracking:'.$guest->id);

        return NotificationLog::query()->where('guest_id', $guest->id)->latest('id')->firstOrFail();
    }

    /** @return array<int, SentMessage> */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_an_accepted_email_is_recorded_and_carries_its_signed_reference(): void
    {
        $log = $this->remind(Guest::factory()->for($this->event)->create(['email' => 'guest@example.test']));

        $this->assertSame(NotificationLog::DELIVERY_ACCEPTED, $log->fresh()->delivery_status);
        $this->assertCount(1, $sent = $this->sent());

        $ref = $sent[0]->getOriginalMessage()->getHeaders()->get('X-EventHost-Ref')?->getBodyAsString();
        $this->assertSame(NotificationLog::mailRef($log->id), $ref);
        $this->assertSame($log->id, NotificationLog::idFromMailRef($ref));
        $this->assertNull(NotificationLog::idFromMailRef($log->id.'.0000000000000000'), 'a forged reference is refused');
    }

    public function test_every_message_goes_out_with_the_bounce_address_as_envelope_sender(): void
    {
        config(['mail.bounce_address' => 'bounces@eventhost.test']);

        $this->remind(Guest::factory()->for($this->event)->create(['email' => 'guest@example.test']));

        $message = $this->sent()[0];
        $this->assertSame('bounces@eventhost.test', $message->getEnvelope()->getSender()->getAddress());
        $this->assertSame('bounces@eventhost.test', $message->getOriginalMessage()->getReturnPath()?->getAddress());
    }

    public function test_an_address_the_mailer_refuses_fails_at_once_with_a_plain_reason(): void
    {
        config(['queue.default' => 'database']);
        $guest = Guest::factory()->for($this->event)->create();
        $guest->forceFill(['email' => 'bad address@example.test'])->save();

        $log = $this->remind($guest);
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--queue' => 'default', '--memory' => 4096]);

        $log->refresh();
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);
        $this->assertSame(NotificationLog::DELIVERY_FAILED, $log->delivery_status);
        $this->assertSame('invalid_address', $log->error_code);
        $this->assertSame('The address is not valid', $log->undeliveredReason());
        $this->assertStringNotContainsString('bad address', (string) $log->response, 'the address is never stored in the log');
        $this->assertSame(0, DB::table('jobs')->count(), 'not retried');
        $this->assertCount(0, $this->sent());
    }

    public function test_a_mail_server_outage_is_retried_after_a_minute_not_at_once(): void
    {
        config(['queue.default' => 'database']);
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection refused');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        $log = $this->remind(Guest::factory()->for($this->event)->create(['email' => 'guest@example.test']));
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'the job is released for another try');
        $this->assertSame(1, (int) $job->attempts);
        $this->assertSame(now()->getTimestamp() + 60, (int) $job->available_at);
        $this->assertSame(4, json_decode($job->payload, true)['maxTries']);
        $this->assertNull($log->fresh()->delivery_status, 'not failed while tries remain');
    }

    public function test_the_last_failed_attempt_is_recorded_without_the_message(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['email' => 'guest@example.test']);
        $log = NotificationLog::query()->create([
            'event_id' => $this->event->id, 'guest_id' => $guest->id, 'channel' => 'email',
            'type' => 'rsvp_reminder', 'status' => NotificationLog::STATUS_SENT,
        ]);

        (new RsvpReminderNotification($this->event, $guest, 3))->withLog($log)
            ->failed(new TransportException('Connection to guest@example.test refused'));

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_FAILED, $log->delivery_status);
        $this->assertSame('transport', $log->error_code);
        $this->assertSame("The mail server couldn't be reached", $log->undeliveredReason());
        $this->assertStringNotContainsString('guest@example.test', (string) $log->response);
    }

    public function test_the_failover_mailer_never_writes_mail_to_the_log(): void
    {
        $this->assertNotContains('log', config('mail.mailers.failover.mailers'));
    }

    public function test_a_guest_email_needs_a_real_domain(): void
    {
        foreach (['john@localhost', 'john@example.', 'john@.example.com', 'john@example..com'] as $email) {
            $this->assertFalse(GuestEmail::hasDottedDomain($email), $email);
        }
        $this->assertTrue(GuestEmail::hasDottedDomain('john@example.co.zm'));

        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'John', 'email' => 'john@localhost'])
            ->assertSessionHasErrors(['email' => GuestEmail::MESSAGE]);
    }

    public function test_a_guest_saved_with_an_old_style_address_can_still_be_edited(): void
    {
        $guest = Guest::factory()->for($this->event)->create();
        $guest->forceFill(['email' => 'john@localhost'])->save();

        $this->actingAs($this->host)
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest->id]), ['name' => 'Renamed', 'email' => 'john@localhost'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $guest->fresh()->name);
    }
}
