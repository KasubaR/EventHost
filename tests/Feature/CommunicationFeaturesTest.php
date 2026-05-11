<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\RsvpReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CommunicationFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_rsvp_reminder_command_logs_sent_and_is_idempotent(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
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

        $owner = User::factory()->create();
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
}
