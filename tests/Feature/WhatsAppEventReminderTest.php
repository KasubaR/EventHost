<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\CommunicationService;
use App\Services\WhatsAppService;
use App\Support\WhatsAppEventReminderBuckets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppEventReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communications.whatsapp.enabled', true);
        config()->set('services.twilio.event_reminder_content_sid', 'HXreminder');
        Carbon::setTestNow(Carbon::parse('2026-12-05 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sends_seven_day_reminder_to_accepted_guest(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedAcceptedGuest(eventDate: '2026-12-12');

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(1, $fake->calls);
        $this->assertSame(
            WhatsAppEventReminderBuckets::leadForBucket($event->name, WhatsAppEventReminderBuckets::BUCKET_7),
            $fake->lastVariables['1'] ?? null
        );
        $this->assertSame('12 December 2026', $fake->lastVariables['2'] ?? null);
        $this->assertContains(WhatsAppEventReminderBuckets::BUCKET_7, $guest->fresh()->whatsapp_event_reminders_sent);
        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'channel' => 'whatsapp',
            'type' => 'guest_event_reminder_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
        ]);
    }

    public function test_sends_one_day_and_event_day_leads(): void
    {
        $fake = $this->bindWhatsAppFake();

        Carbon::setTestNow(Carbon::parse('2026-12-11 10:00:00'));
        [$eventTomorrow, $guestTomorrow] = $this->seedAcceptedGuest(eventDate: '2026-12-12', phone: '+260971111111');
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();
        $this->assertStringContainsString('tomorrow', (string) ($fake->lastVariables['1'] ?? ''));
        $this->assertContains(WhatsAppEventReminderBuckets::BUCKET_1, $guestTomorrow->fresh()->whatsapp_event_reminders_sent);

        Carbon::setTestNow(Carbon::parse('2026-12-12 10:00:00'));
        [$eventToday, $guestToday] = $this->seedAcceptedGuest(eventDate: '2026-12-12', phone: '+260972222222');
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();
        $this->assertStringContainsString('big day', (string) ($fake->lastVariables['1'] ?? ''));
        $this->assertContains(WhatsAppEventReminderBuckets::BUCKET_0, $guestToday->fresh()->whatsapp_event_reminders_sent);
    }

    public function test_skips_declined_guest(): void
    {
        $fake = $this->bindWhatsAppFake();
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create(['event_date' => '2026-12-12']);
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);
        Rsvp::factory()->for($guest)->create([
            'event_id' => $event->id,
            'status' => RsvpStatus::Declined,
            'attendee_count' => 0,
        ]);

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $fake->calls);
        $this->assertSame([], $guest->fresh()->whatsapp_event_reminders_sent);
    }

    public function test_skips_already_sent_bucket(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedAcceptedGuest(eventDate: '2026-12-12');
        $guest->forceFill([
            'whatsapp_event_reminders_sent' => [WhatsAppEventReminderBuckets::BUCKET_7],
        ])->save();

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $fake->calls);
    }

    public function test_skips_base_tier_host(): void
    {
        $fake = $this->bindWhatsAppFake();
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create(['event_date' => '2026-12-12']);
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);
        Rsvp::factory()->for($guest)->create([
            'event_id' => $event->id,
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
        ]);

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $fake->calls);
    }

    public function test_skips_when_whatsapp_disabled(): void
    {
        config()->set('communications.whatsapp.enabled', false);
        $fake = $this->bindWhatsAppFake();
        $this->seedAcceptedGuest(eventDate: '2026-12-12');

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $fake->calls);
    }

    // ── plans/guest-email-reminders.md Phase 1 ───────────────────────────────────────

    public function test_a_cancelled_event_sends_no_reminders_and_an_uncancelled_one_does(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event] = $this->seedAcceptedGuest(eventDate: '2026-12-12');
        $event->forceFill(['cancelled_at' => now()])->save();

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $fake->calls, 'guests of a cancelled event must not be told it is a week away');
        $this->assertDatabaseCount('notification_logs', 0);

        $event->forceFill(['cancelled_at' => null])->save();

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(1, $fake->calls);
    }

    public function test_the_sender_itself_refuses_a_cancelled_or_deleted_event(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedAcceptedGuest(eventDate: '2026-12-12');
        $service = app(CommunicationService::class);

        $event->forceFill(['cancelled_at' => now()])->save();
        $this->assertSame('skipped', $service->sendWhatsAppEventReminder($event->fresh(), $guest, '7'));

        $event->forceFill(['cancelled_at' => null])->save();
        $event->delete();
        $this->assertSame('skipped', $service->sendWhatsAppEventReminder(Event::withTrashed()->find($event->id), $guest, '7'));

        $this->assertSame(0, $fake->calls);
    }

    public function test_drafts_and_ticketed_events_are_not_reminded(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$draft] = $this->seedAcceptedGuest(eventDate: '2026-12-12');
        $draft->forceFill(['is_published' => false])->save();

        $owner = User::factory()->proPlus()->create();
        $ticketed = Event::factory()->for($owner)->ticketed()->create(['event_date' => '2026-12-12', 'is_published' => true]);
        $guest = Guest::factory()->for($ticketed)->create(['phone' => '+260973333333']);
        Rsvp::factory()->for($guest)->create(['event_id' => $ticketed->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(0, $fake->calls);
    }

    public function test_a_paused_invitation_is_still_reminded(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event] = $this->seedAcceptedGuest(eventDate: '2026-12-12');
        $event->forceFill(['invitation_paused_at' => now()])->save();

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(1, $fake->calls, 'pausing stops new responses; the event is still happening');
    }

    public function test_the_log_key_carries_the_event_date(): void
    {
        $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedAcceptedGuest(eventDate: '2026-12-12');

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertDatabaseHas('notification_logs', [
            'guest_id' => $guest->id,
            'idempotency_key' => 'wa-event-reminder:'.$event->id.':'.$guest->id.':7:2026-12-12',
        ]);
    }

    public function test_a_moved_event_is_reminded_again_for_its_new_date(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedAcceptedGuest(eventDate: '2026-12-12');

        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();
        $this->assertSame(1, $fake->calls);
        $this->assertContains('7', $guest->fresh()->whatsapp_event_reminders_sent);

        // Same day, second run: still exactly once.
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();
        $this->assertSame(1, $fake->calls);

        // The host moves the event a day later; tomorrow it is 7 days away again.
        $event->update(['event_date' => '2026-12-13']);
        $this->assertSame([], $guest->fresh()->whatsapp_event_reminders_sent, 'moving the date re-arms the reminders');

        Carbon::setTestNow(Carbon::parse('2026-12-06 10:00:00'));
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(2, $fake->calls);
        $this->assertSame('13 December 2026', $fake->lastVariables['2'] ?? null);
    }

    public function test_changing_something_other_than_the_date_does_not_re_arm_reminders(): void
    {
        $fake = $this->bindWhatsAppFake();
        [$event, $guest] = $this->seedAcceptedGuest(eventDate: '2026-12-12');
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $event->update(['venue' => 'Somewhere else', 'event_time' => '16:00:00']);
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $this->assertSame(1, $fake->calls);
        $this->assertContains('7', $guest->fresh()->whatsapp_event_reminders_sent);
    }

    public function test_moving_one_event_leaves_other_events_guests_alone(): void
    {
        $this->bindWhatsAppFake();
        [$moved] = $this->seedAcceptedGuest(eventDate: '2026-12-12', phone: '+260971111111');
        [, $other] = $this->seedAcceptedGuest(eventDate: '2026-12-12', phone: '+260972222222');
        $this->artisan('events:send-whatsapp-reminders')->assertSuccessful();

        $moved->update(['event_date' => '2026-12-20']);

        $this->assertContains('7', $other->fresh()->whatsapp_event_reminders_sent);
    }

    /**
     * @return object{calls: int, lastVariables: ?array}&WhatsAppService
     */
    private function bindWhatsAppFake(): object
    {
        $fake = new class implements WhatsAppService
        {
            public int $calls = 0;

            /** @var array<string, string>|null */
            public ?array $lastVariables = null;

            public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
            {
                $this->calls++;
                $this->lastVariables = $templateVariables;

                return ['status' => 'sent', 'provider_message_id' => 'SM_REM_'.$this->calls, 'response' => null];
            }

            public function sendText(string $toE164Phone, string $body): array
            {
                return ['status' => 'skipped', 'provider_message_id' => null, 'response' => null];
            }

            public function sendMedia(string $toE164Phone, string $mediaUrl, ?string $caption = null): array
            {
                return ['status' => 'skipped', 'provider_message_id' => null, 'response' => null];
            }
        };
        $this->app->instance(WhatsAppService::class, $fake);

        return $fake;
    }

    /**
     * @return array{0: Event, 1: Guest}
     */
    private function seedAcceptedGuest(string $eventDate, string $phone = '+260971234567'): array
    {
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'event_date' => $eventDate,
            'event_time' => '14:00:00',
            'venue' => 'Ciela Resort',
            'name' => "Mary's wedding",
        ]);
        $guest = Guest::factory()->for($event)->create(['phone' => $phone]);
        Rsvp::factory()->for($guest)->create([
            'event_id' => $event->id,
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 1,
        ]);

        return [$event, $guest];
    }
}
