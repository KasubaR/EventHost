<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
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
