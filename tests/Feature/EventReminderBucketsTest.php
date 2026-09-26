<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\EventReminderBuckets;
use App\Support\WhatsAppEventReminderBuckets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * plans/guest-email-reminders.md Phase 1 — the reminder schedule and the event selection that the
 * WhatsApp reminder uses today and the email reminder will.
 */
class EventReminderBucketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_seven_one_and_zero_days_out_are_reminder_days(): void
    {
        $this->assertSame('7', EventReminderBuckets::forDaysUntil(7));
        $this->assertSame('1', EventReminderBuckets::forDaysUntil(1));
        $this->assertSame('0', EventReminderBuckets::forDaysUntil(0));

        foreach ([-1, 2, 3, 6, 8, 30] as $days) {
            $this->assertNull(EventReminderBuckets::forDaysUntil($days), "day {$days} is not a reminder day");
        }
    }

    public function test_the_bucket_for_an_event_ignores_the_time_of_day(): void
    {
        $event = Event::factory()->make(['event_date' => '2026-12-12']);

        foreach (['2026-12-05 00:01:00', '2026-12-05 09:00:00', '2026-12-05 23:59:00'] as $moment) {
            $this->assertSame('7', EventReminderBuckets::forEvent($event, Carbon::parse($moment)), $moment);
        }

        $this->assertSame('1', EventReminderBuckets::forEvent($event, Carbon::parse('2026-12-11 18:00:00')));
        $this->assertSame('0', EventReminderBuckets::forEvent($event, Carbon::parse('2026-12-12 09:00:00')));
        $this->assertNull(EventReminderBuckets::forEvent($event, Carbon::parse('2026-12-13 09:00:00')), 'after the event');
    }

    public function test_an_undated_event_has_no_bucket(): void
    {
        $this->assertNull(EventReminderBuckets::forEvent(Event::factory()->make(['event_date' => null])));
    }

    public function test_whatsapp_and_the_shared_class_use_the_same_ids_and_wording(): void
    {
        $this->assertSame(EventReminderBuckets::ALL, WhatsAppEventReminderBuckets::ALL);

        foreach (EventReminderBuckets::ALL as $bucket) {
            $this->assertSame(
                EventReminderBuckets::lead('Mary & David', $bucket),
                WhatsAppEventReminderBuckets::leadForBucket('Mary & David', $bucket),
            );
        }

        $this->assertStringContainsString('one week away', EventReminderBuckets::lead('X', '7'));
        $this->assertStringContainsString('tomorrow', EventReminderBuckets::lead('X', '1'));
        $this->assertStringContainsString('big day', EventReminderBuckets::lead('X', '0'));
    }

    public function test_the_selection_scope_takes_only_reminder_eligible_events(): void
    {
        $now = Carbon::parse('2026-12-05 09:00:00');
        $make = fn (array $attrs = []) => Event::factory()->published()->create($attrs + ['event_date' => '2026-12-12']);

        $eligible = $make();
        $today = $make(['event_date' => '2026-12-05']);

        $excluded = [
            'cancelled' => $make(['cancelled_at' => now()]),
            'draft' => $make(['is_published' => false]),
            'past' => $make(['event_date' => '2026-12-04']),
            'too far out' => $make(['event_date' => '2026-12-13']),
            'ticketed' => Event::factory()->ticketed()->create(['event_date' => '2026-12-12', 'is_published' => true]),
        ];
        $deleted = $make();
        $deleted->delete();

        $ids = Event::query()->dueForGuestEventReminder($now)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$eligible->id, $today->id], $ids);

        foreach ($excluded as $why => $event) {
            $this->assertNotContains($event->id, $ids, "{$why} must not be selected");
        }
        $this->assertNotContains($deleted->id, $ids, 'deleted must not be selected');
    }
}
