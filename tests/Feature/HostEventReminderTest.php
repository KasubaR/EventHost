<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\HostEventReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HostEventReminderTest extends TestCase
{
    use RefreshDatabase;

    private function eventIn(int $days, ?User $owner = null, array $overrides = []): Event
    {
        return Event::factory()->for($owner ?? User::factory()->create())->published()->create(array_merge([
            'event_date' => now()->startOfDay()->addDays($days),
        ], $overrides));
    }

    public function test_host_is_emailed_seven_days_and_one_day_before(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $week = $this->eventIn(7, $owner);
        $tomorrow = $this->eventIn(1, $owner);
        $this->eventIn(3, $owner);

        $this->artisan('events:send-host-reminders')->assertSuccessful();

        Notification::assertSentToTimes($owner, HostEventReminderNotification::class, 2);
        Notification::assertSentTo($owner, HostEventReminderNotification::class,
            fn ($n) => $n->event->is($week) && $n->daysUntilEvent === 7);
        Notification::assertSentTo($owner, HostEventReminderNotification::class,
            fn ($n) => $n->event->is($tomorrow) && $n->daysUntilEvent === 1);
    }

    public function test_running_twice_does_not_send_twice(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $this->eventIn(1, $owner);

        $this->artisan('events:send-host-reminders')->assertSuccessful();
        $this->artisan('events:send-host-reminders')->assertSuccessful();

        Notification::assertSentToTimes($owner, HostEventReminderNotification::class, 1);
        $this->assertSame(1, NotificationLog::query()->where('type', 'host_event_reminder')->count());
    }

    public function test_opted_out_host_is_not_emailed(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $owner->forceFill(['notification_preferences' => array_merge(
            $owner->notification_preferences ?? [], ['email_event_reminders' => false]
        )])->save();
        $this->eventIn(1, $owner);

        $this->artisan('events:send-host-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_drafts_and_cancelled_events_are_skipped(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        Event::factory()->for($owner)->create(['event_date' => now()->startOfDay()->addDay(), 'is_published' => false]);
        $this->eventIn(1, $owner, ['cancelled_at' => now()]);

        $this->artisan('events:send-host-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_mail_content_names_event_and_lead_time(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventIn(1, $owner, ['name' => 'Amy and Joe Wedding']);

        $mail = (new HostEventReminderNotification($event, 1))->toMail($owner);

        $this->assertSame('Reminder: Amy and Joe Wedding is tomorrow', $mail->subject);
    }
}
