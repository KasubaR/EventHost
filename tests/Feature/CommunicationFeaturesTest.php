<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\RsvpReminderNotification;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CommunicationFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_rsvp_reminder_command_logs_sent_and_is_idempotent(): void
    {
        Notification::fake();

        // Automated reminders are Pro+ only — see Event::ownerCanSendAutomatedReminders().
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'is_public' => true,
            'rsvp_deadline' => now()->addDays(3),
        ]);

        $guest = Guest::factory()->for($event)->create([
            'email' => 'guest@example.test',
            'invitation_token' => null,
            'rsvp_reminders_sent' => [],
        ]);

        $this->artisan('rsvp:send-reminders')
            ->assertSuccessful();

        Notification::assertSentOnDemand(RsvpReminderNotification::class);
        $this->assertDatabaseHas('notification_logs', [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'channel' => 'email',
            'type' => 'rsvp_reminder',
            'status' => NotificationLog::STATUS_SENT,
            'idempotency_key' => sprintf('rsvp-reminder:%d:%d:%s', $event->id, $guest->id, '3'),
        ]);

        $this->artisan('rsvp:send-reminders')
            ->assertSuccessful();

        $this->assertSame(1, NotificationLog::query()
            ->where('idempotency_key', sprintf('rsvp-reminder:%d:%d:%s', $event->id, $guest->id, '3'))
            ->count());
    }

    public function test_bulk_send_reminder_logs_and_marks_bucket(): void
    {
        Notification::fake();

        // Automated reminders are Pro+ only — see Event::ownerCanSendAutomatedReminders().
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create([
            'email' => 'bulk@example.test',
            'rsvp_reminders_sent' => [],
        ]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'send_reminder_email',
                'guest_ids' => [$guest->id],
                'days_until' => 3,
            ])
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionHas('status', 'guests-bulk-reminder');

        Notification::assertSentOnDemand(RsvpReminderNotification::class);
        $this->assertDatabaseHas('notification_logs', [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'rsvp_reminder',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        $this->assertContains('3', $guest->fresh()->rsvp_reminders_sent);
    }

    public function test_bulk_send_update_is_rate_limited_and_logs_email_send(): void
    {
        Notification::fake();
        config()->set('communications.bulk_send_per_hour', 1);

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['email' => 'update@example.test']);

        $payload = [
            'action' => 'send_update_email',
            'guest_ids' => [$guest->id],
            'update_message' => 'Venue changed to Garden Court.',
        ];

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), $payload)
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionHas('status', 'guests-bulk-update');

        Notification::assertSentOnDemand(EventUpdatedNotification::class);
        $this->assertDatabaseHas('notification_logs', [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'type' => 'event_update',
            'status' => NotificationLog::STATUS_SENT,
        ]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), $payload)
            ->assertStatus(429);
    }

    public function test_pro_host_can_send_whatsapp_invitation(): void
    {
        config()->set('communications.whatsapp.enabled', true);
        config()->set('services.twilio.invitation_content_sid', 'HXtest');

        $fake = new class implements WhatsAppService
        {
            public int $calls = 0;

            /** @var array<string, string>|null */
            public ?array $lastVariables = null;

            public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
            {
                $this->calls++;
                $this->lastVariables = $templateVariables;

                return ['status' => 'sent', 'provider_message_id' => 'SM123', 'response' => null];
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

        // Gated same as check-in/table assignment/photo wall — see Event::ownerHasPremiumEventTools().
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'name' => 'Mary & David Wedding',
            'event_date' => '2026-12-12',
            'event_time' => '14:00:00',
            'venue' => 'Ciela Resort',
            'cover_image' => null,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'name' => 'John',
            'phone' => '+260971234567',
            'invitation_token' => 'tok_whatsapp_assert_48chars_abcdefghijklmnop',
        ]);

        $this->actingAs($owner)
            ->post(route('events.guests.whatsapp-invite', ['event' => $event, 'guest' => $guest]))
            ->assertRedirect()
            ->assertSessionHas('status', 'guest-whatsapp-sent');

        $this->assertSame(1, $fake->calls);
        $this->assertSame([
            '1' => 'John',
            '2' => 'Mary & David Wedding',
            '3' => '12 December 2026',
            '4' => '14:00',
            '5' => 'Ciela Resort',
            '6' => $guest->personalRsvpUrl(),
            '7' => 'images/default-event.png',
        ], $fake->lastVariables);
        $this->assertStringStartsWith('http', (string) ($fake->lastVariables['6'] ?? ''));
        $this->assertDatabaseHas('notification_logs', [
            'event_id' => $event->id,
            'guest_id' => $guest->id,
            'channel' => 'whatsapp',
            'type' => 'guest_invitation_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
            'provider_message_id' => 'SM123',
        ]);
        $this->assertTrue($guest->fresh()->invitation_sent);
    }

    public function test_base_host_is_forbidden_from_sending_whatsapp_invitation(): void
    {
        config()->set('communications.whatsapp.enabled', true);

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => '+260971234567']);

        $this->actingAs($owner)
            ->post(route('events.guests.whatsapp-invite', ['event' => $event, 'guest' => $guest]))
            ->assertForbidden();

        $this->assertDatabaseMissing('notification_logs', ['guest_id' => $guest->id]);
    }

    public function test_whatsapp_invitation_reports_invalid_phone_without_calling_the_provider(): void
    {
        config()->set('communications.whatsapp.enabled', true);

        $fake = new class implements WhatsAppService
        {
            public int $calls = 0;

            public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
            {
                $this->calls++;

                return ['status' => 'sent', 'provider_message_id' => 'SM123', 'response' => null];
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

        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['phone' => null]);

        $this->actingAs($owner)
            ->post(route('events.guests.whatsapp-invite', ['event' => $event, 'guest' => $guest]))
            ->assertRedirect()
            ->assertSessionHas('status', 'guest-whatsapp-invalid-phone');

        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseMissing('notification_logs', ['guest_id' => $guest->id]);
    }

    public function test_whatsapp_invitation_respects_the_per_event_hourly_cap(): void
    {
        config()->set('communications.whatsapp.enabled', true);
        config()->set('communications.whatsapp.hourly_cap_per_event', 1);

        $fake = new class implements WhatsAppService
        {
            public int $calls = 0;

            public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
            {
                $this->calls++;

                return ['status' => 'sent', 'provider_message_id' => 'SM123', 'response' => null];
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

        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $alreadySent = Guest::factory()->for($event)->create(['phone' => '+260971234567']);
        NotificationLog::query()->create([
            'event_id' => $event->id,
            'guest_id' => $alreadySent->id,
            'channel' => 'whatsapp',
            'type' => 'guest_invitation_whatsapp',
            'status' => NotificationLog::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $secondGuest = Guest::factory()->for($event)->create(['phone' => '+260977654321']);

        $this->actingAs($owner)
            ->post(route('events.guests.whatsapp-invite', ['event' => $event, 'guest' => $secondGuest]))
            ->assertRedirect()
            ->assertSessionHas('status', 'guest-whatsapp-rate-limited');

        $this->assertSame(0, $fake->calls);
        $this->assertDatabaseMissing('notification_logs', ['guest_id' => $secondGuest->id]);
    }
}
