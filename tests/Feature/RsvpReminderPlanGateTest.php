<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\RsvpReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Automated RSVP reminder emails are Pro+ only — matches the homepage's
 * "Email + WhatsApp reminders" bullet (the WhatsApp half has no automated
 * send mechanism at all, so it isn't gated, there's nothing to gate).
 * See Event::ownerCanSendAutomatedReminders() / User::canSendAutomatedReminders().
 */
class RsvpReminderPlanGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reminderReadyEvent(User $owner, array $overrides = []): Event
    {
        return Event::factory()->for($owner)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => now()->addDays(3),
        ], $overrides));
    }

    public function test_scheduled_command_skips_base_and_pro_events(): void
    {
        Notification::fake();

        $base = User::factory()->create(); // default factory tier is Base
        $baseEvent = $this->reminderReadyEvent($base);
        $baseGuest = Guest::factory()->for($baseEvent)->create([
            'email' => 'base-guest@example.test',
            'rsvp_reminders_sent' => [],
        ]);

        $pro = User::factory()->pro()->create();
        $proEvent = $this->reminderReadyEvent($pro);
        Guest::factory()->for($proEvent)->create([
            'email' => 'pro-guest@example.test',
            'rsvp_reminders_sent' => [],
        ]);

        $this->artisan('rsvp:send-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, NotificationLog::query()->where('type', 'rsvp_reminder')->count());
        $this->assertSame([], $baseGuest->fresh()->rsvp_reminders_sent);
    }

    public function test_scheduled_command_sends_for_pro_plus_events(): void
    {
        Notification::fake();

        $owner = User::factory()->proPlus()->create();
        $event = $this->reminderReadyEvent($owner);
        $guest = Guest::factory()->for($event)->create([
            'email' => 'proplus-guest@example.test',
            'rsvp_reminders_sent' => [],
        ]);

        $this->artisan('rsvp:send-reminders')->assertSuccessful();

        Notification::assertSentOnDemand(RsvpReminderNotification::class);
        $this->assertContains('3', $guest->fresh()->rsvp_reminders_sent);
    }

    public function test_bulk_reminder_action_is_blocked_for_a_base_event_and_does_not_mark_the_bucket_sent(): void
    {
        Notification::fake();

        $owner = User::factory()->create(); // Base
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create([
            'email' => 'bulk-base@example.test',
            'rsvp_reminders_sent' => [],
        ]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'send_reminder_email',
                'guest_ids' => [$guest->id],
                'days_until' => 3,
            ])
            ->assertSessionHasErrors('action');

        Notification::assertNothingSent();
        // The bucket must stay unmarked — otherwise upgrading later would
        // never re-send this reminder, since it would already read "sent".
        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent);
    }

    public function test_bulk_reminder_action_works_for_a_pro_plus_event(): void
    {
        Notification::fake();

        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create([
            'email' => 'bulk-proplus@example.test',
            'rsvp_reminders_sent' => [],
        ]);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'send_reminder_email',
                'guest_ids' => [$guest->id],
                'days_until' => 3,
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('status', 'guests-bulk-reminder');

        Notification::assertSentOnDemand(RsvpReminderNotification::class);
        $this->assertContains('3', $guest->fresh()->rsvp_reminders_sent);
    }

    public function test_ticketed_events_never_qualify_for_automated_reminders(): void
    {
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->assertFalse($event->ownerCanSendAutomatedReminders());
    }
}
