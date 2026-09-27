<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\GuestEventReminderNotification;
use App\Notifications\RsvpReminderNotification;
use App\Services\CommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * plans/guest-email-reminders.md Phase 3 — a guest can stop reminder emails from the link in one.
 */
class GuestEmailReminderOptOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communications.guest_email_reminders.enabled', true);
        Carbon::setTestNow(Carbon::parse('2026-12-05 07:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{0: Event, 1: Guest}
     */
    private function acceptedGuest(string $email = 'guest@example.test', bool $withToken = true): array
    {
        $event = Event::factory()->for(User::factory()->proPlus()->create())->published()->create([
            'event_date' => '2026-12-12',
            'event_time' => '14:00:00',
            'name' => "Mary's wedding",
            'is_public' => true,
            'rsvp_deadline' => now()->addDays(3),
        ]);
        $factory = Guest::factory()->for($event);
        $guest = ($withToken ? $factory : $factory->withoutToken())->create(['email' => $email]);

        return [$event, $guest];
    }

    private function accept(Event $event, Guest $guest): void
    {
        Rsvp::factory()->for($guest)->create(['event_id' => $event->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
    }

    private function eventMail(Event $event, Guest $guest): MailMessage
    {
        return (new GuestEventReminderNotification($event, $guest->load('rsvp'), '7'))->toMail(new AnonymousNotifiable);
    }

    // ── The page ─────────────────────────────────────────────────────────────────────

    public function test_opening_the_link_shows_a_confirmation_and_changes_nothing(): void
    {
        [$event, $guest] = $this->acceptedGuest();

        $this->get($guest->stopEmailRemindersPath())
            ->assertOk()
            ->assertSee($event->name)
            ->assertSee('Stop reminder emails');

        $this->assertNull($guest->fresh()->email_reminders_stopped_at, 'a mail scanner fetching the link must not opt anyone out');
    }

    public function test_confirming_stops_the_reminders_and_offers_an_undo(): void
    {
        [, $guest] = $this->acceptedGuest();

        $this->post($guest->stopEmailRemindersPath())
            ->assertOk()
            ->assertSee('You will not get any more reminder emails')
            ->assertSee('Send me reminders again');

        $this->assertTrue($guest->fresh()->hasStoppedEmailReminders());
    }

    public function test_stopping_twice_keeps_the_first_timestamp(): void
    {
        [, $guest] = $this->acceptedGuest();

        $this->post($guest->stopEmailRemindersPath())->assertOk();
        $first = $guest->fresh()->email_reminders_stopped_at;

        Carbon::setTestNow(now()->addDay());
        $this->post($guest->stopEmailRemindersPath())->assertOk();

        $this->assertTrue($first->equalTo($guest->fresh()->email_reminders_stopped_at));
    }

    public function test_the_page_reflects_an_already_stopped_guest_and_the_undo_resumes(): void
    {
        [, $guest] = $this->acceptedGuest();
        $this->post($guest->stopEmailRemindersPath());

        $this->get($guest->stopEmailRemindersPath())->assertOk()->assertSee('You will not get any more reminder emails');

        $this->post($guest->resumeEmailRemindersPath())
            ->assertOk()
            ->assertSee('Reminder emails are back on');

        $this->assertFalse($guest->fresh()->hasStoppedEmailReminders());
    }

    public function test_it_needs_a_valid_signature(): void
    {
        [, $guest] = $this->acceptedGuest();
        [, $other] = $this->acceptedGuest('other@example.test');

        $this->get("/reminders/{$guest->id}/stop")->assertForbidden();
        $this->post("/reminders/{$guest->id}/stop")->assertForbidden();
        $this->post("/reminders/{$guest->id}/resume")->assertForbidden();

        // A valid signature for someone else's guest id is not valid for this one.
        $forged = str_replace("/reminders/{$other->id}/", "/reminders/{$guest->id}/", $other->stopEmailRemindersPath());
        $this->post($forged)->assertForbidden();

        $this->assertFalse($guest->fresh()->hasStoppedEmailReminders());
        $this->assertFalse($other->fresh()->hasStoppedEmailReminders());
    }

    public function test_a_mail_clients_one_click_post_works_without_a_csrf_token(): void
    {
        [, $guest] = $this->acceptedGuest();

        $this->post($guest->stopEmailRemindersPath(), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertTrue($guest->fresh()->hasStoppedEmailReminders());
    }

    public function test_the_signature_holds_whichever_host_the_request_arrives_on(): void
    {
        [, $guest] = $this->acceptedGuest();

        // The bare domain redirects to www; an absolute signature would fail after the hop.
        $this->get('https://www.eventhostzm.com'.$guest->stopEmailRemindersPath())->assertOk();
        $this->post('https://eventhostzm.com'.$guest->stopEmailRemindersPath())->assertOk();
    }

    public function test_it_works_for_a_guest_with_no_rsvp_token(): void
    {
        [, $guest] = $this->acceptedGuest(withToken: false);

        $this->assertNull($guest->invitation_token);
        $this->post($guest->stopEmailRemindersPath())->assertOk();

        $this->assertTrue($guest->fresh()->hasStoppedEmailReminders());
    }

    public function test_the_page_still_works_after_the_event_is_deleted(): void
    {
        [$event, $guest] = $this->acceptedGuest();
        $event->delete();

        $this->post($guest->stopEmailRemindersPath())->assertOk()->assertSee('this event');

        $this->assertTrue($guest->fresh()->hasStoppedEmailReminders());
    }

    public function test_the_routes_are_throttled(): void
    {
        foreach (['guest.email-reminders.show', 'guest.email-reminders.stop', 'guest.email-reminders.resume'] as $name) {
            $this->assertContains('throttle:30,1', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    // ── It actually stops the emails ─────────────────────────────────────────────────

    public function test_a_stopped_guest_gets_no_event_reminder_but_others_still_do(): void
    {
        Notification::fake();
        [$event, $stopped] = $this->acceptedGuest('stopped@example.test');
        $this->accept($event, $stopped);
        $stayer = Guest::factory()->for($event)->create(['email' => 'stays@example.test']);
        $this->accept($event, $stayer);
        $stopped->forceFill(['email_reminders_stopped_at' => now()])->save();

        $this->artisan('events:send-guest-email-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
        Notification::assertSentOnDemand(GuestEventReminderNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'stays@example.test');
        $this->assertSame(
            'skipped',
            app(CommunicationService::class)->sendGuestEventReminderEmail($event, $stopped->fresh(), '7'),
        );
    }

    public function test_a_stopped_guest_gets_no_rsvp_deadline_reminder(): void
    {
        Notification::fake();
        [$event, $guest] = $this->acceptedGuest('deadline@example.test');
        $guest->forceFill(['email_reminders_stopped_at' => now()])->save();
        $other = Guest::factory()->for($event)->create(['email' => 'other@example.test']);

        $this->artisan('rsvp:send-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(RsvpReminderNotification::class, 1);
        Notification::assertSentOnDemand(RsvpReminderNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === $other->email);
        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent, 'and the bucket is not marked as sent');
    }

    public function test_the_sender_for_the_deadline_reminder_refuses_a_stopped_guest_too(): void
    {
        Notification::fake();
        [$event, $guest] = $this->acceptedGuest();
        $guest->forceFill(['email_reminders_stopped_at' => now()])->save();

        app(CommunicationService::class)->sendRsvpReminder($event, $guest, 3, 'k');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_a_hosts_bulk_reminder_skips_a_stopped_guest_and_does_not_count_them(): void
    {
        Notification::fake();
        [$event, $stopped] = $this->acceptedGuest('bulk-stopped@example.test');
        $stopped->forceFill(['email_reminders_stopped_at' => now()])->save();
        $other = Guest::factory()->for($event)->create(['email' => 'bulk-other@example.test']);

        $this->actingAs($event->user)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'send_reminder_email',
                'guest_ids' => [$stopped->id, $other->id],
                'days_until' => 3,
            ])
            ->assertRedirect();

        Notification::assertSentOnDemandTimes(RsvpReminderNotification::class, 1);
        Notification::assertSentOnDemand(RsvpReminderNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === $other->email);
        $this->assertSame([], $stopped->fresh()->rsvp_reminders_sent);
    }

    public function test_resuming_makes_the_guest_eligible_again(): void
    {
        Notification::fake();
        [$event, $guest] = $this->acceptedGuest();
        $this->accept($event, $guest);
        $this->post($guest->stopEmailRemindersPath());
        $this->post($guest->resumeEmailRemindersPath());

        $this->artisan('events:send-guest-email-reminders')->assertSuccessful();

        Notification::assertSentOnDemandTimes(GuestEventReminderNotification::class, 1);
    }

    // ── The link and headers in the emails ───────────────────────────────────────────

    public function test_both_reminder_emails_carry_a_working_stop_link_and_unsubscribe_headers(): void
    {
        [$event, $guest] = $this->acceptedGuest();
        $this->accept($event, $guest);

        $mails = [
            'event reminder' => $this->eventMail($event, $guest),
            'rsvp reminder' => (new RsvpReminderNotification($event, $guest, 3))->toMail(new AnonymousNotifiable),
        ];

        foreach ($mails as $which => $mail) {
            $body = implode("\n", [...$mail->introLines, ...$mail->outroLines]);
            $this->assertMatchesRegularExpression('/\[Stop reminder emails\]\((?<url>[^)]+)\)/', $body, $which);
            preg_match('/\[Stop reminder emails\]\((?<url>[^)]+)\)/', $body, $found);
            $this->assertSame($guest->stopEmailRemindersUrl(), $found['url'], $which);

            $email = new Email;
            foreach ($mail->callbacks as $callback) {
                $callback($email);
            }
            $this->assertSame('<'.$guest->stopEmailRemindersUrl().'>', $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(), $which);
            $this->assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString(), $which);

            // The link in the mail is the real thing: the path opens the confirmation page.
            $this->get(parse_url($found['url'], PHP_URL_PATH).'?'.parse_url($found['url'], PHP_URL_QUERY))
                ->assertOk()
                ->assertSee('Stop reminder emails');
        }
    }

    public function test_the_stop_link_is_a_relative_signed_path_on_the_app_url(): void
    {
        [, $guest] = $this->acceptedGuest();

        $path = $guest->stopEmailRemindersPath();

        $this->assertStringStartsWith('/reminders/'.$guest->id.'/stop?', $path);
        $this->assertStringContainsString('signature=', $path);
        $this->assertStringNotContainsString($guest->invitation_token, $path, 'the private RSVP token must not be exposed');
        $this->assertSame(url($path), $guest->stopEmailRemindersUrl());
    }
}
