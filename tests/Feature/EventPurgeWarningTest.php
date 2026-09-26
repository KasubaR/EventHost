<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\NotificationLog;
use App\Models\TicketOrder;
use App\Models\User;
use App\Notifications\HostPurgeWarningNotification;
use App\Services\CommunicationService;
use App\Services\EventPurgeService;
use App\Support\PurgeOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 2c of plans/event-retention.md: the warning email, and the purge's refusal to
 * delete an event whose host was not warned. Purging itself is EventPurgeTest.
 */
class EventPurgeWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config([
            'events.retention.deleted_days' => 30,
            'events.retention.starts_at' => now()->subDays(120)->toDateString(),
        ]);
    }

    /** A deleted event that has NOT been warned about. */
    private function trashed(User $owner, int $daysAgo, array $attributes = [], bool $ticketed = false): Event
    {
        $event = ($ticketed ? Event::factory()->ticketed() : Event::factory())->for($owner)->create($attributes);
        $event->delete();
        Event::withTrashed()->whereKey($event->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

        return Event::withTrashed()->findOrFail($event->id);
    }

    private function warnLogs(): Collection
    {
        return NotificationLog::query()->where('type', 'event_purge_warning')->get();
    }

    // ── The digest ───────────────────────────────────────────────────────────

    public function test_a_host_gets_one_digest_listing_every_event_inside_the_week(): void
    {
        $host = User::factory()->create();
        $a = $this->trashed($host, 25, ['name' => 'Wedding']);
        $b = $this->trashed($host, 28, ['name' => 'Reunion']);
        $c = $this->trashed($host, 26, ['name' => 'Graduation']);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentToTimes($host, HostPurgeWarningNotification::class, 1);
        Notification::assertSentTo($host, HostPurgeWarningNotification::class, function (HostPurgeWarningNotification $n): bool {
            // Soonest first: 28 days old goes in 2 days, 26 in 4, 25 in 5.
            return array_column($n->events, 'name') === ['Reunion', 'Graduation', 'Wedding'];
        });
        $this->assertCount(3, $this->warnLogs());
        $this->assertEqualsCanonicalizing(
            [$a->purgeWarningKey(), $b->purgeWarningKey(), $c->purgeWarningKey()],
            $this->warnLogs()->pluck('idempotency_key')->all(),
        );
    }

    public function test_each_host_gets_their_own_digest(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->trashed($one, 27);
        $this->trashed($one, 27);
        $this->trashed($two, 27);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentToTimes($one, HostPurgeWarningNotification::class, 1);
        Notification::assertSentToTimes($two, HostPurgeWarningNotification::class, 1);
    }

    public function test_the_window_starts_exactly_seven_days_before_the_purge_date(): void
    {
        $host = User::factory()->create();
        $inside = $this->trashed($host, 23, ['name' => 'Inside']);   // purges in 7 days
        $outside = $this->trashed($host, 22, ['name' => 'Outside']); // purges in 8 days

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentTo($host, HostPurgeWarningNotification::class, fn ($n) => array_column($n->events, 'name') === ['Inside']);
        $this->assertSame([$inside->purgeWarningKey()], $this->warnLogs()->pluck('idempotency_key')->all());
        $this->assertNotContains($outside->purgeWarningKey(), $this->warnLogs()->pluck('idempotency_key')->all());
    }

    public function test_an_event_already_past_due_is_warned_too(): void
    {
        $host = User::factory()->create();
        $this->trashed($host, 45, ['name' => 'Overdue']);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentTo($host, HostPurgeWarningNotification::class);
    }

    public function test_an_event_that_will_be_kept_is_never_warned_about(): void
    {
        $host = User::factory()->create();
        $ticketed = $this->trashed($host, 28, ticketed: true);
        TicketOrder::factory()->for($ticketed)->paid()->create();
        $pledged = $this->trashed($host, 28);
        EventContribution::factory()->for($pledged)->create(['amount_paid' => '25.00']);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertCount(0, $this->warnLogs());
    }

    public function test_running_it_twice_sends_once(): void
    {
        $host = User::factory()->create();
        $this->trashed($host, 27);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();
        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentToTimes($host, HostPurgeWarningNotification::class, 1);
        $this->assertCount(1, $this->warnLogs());
    }

    public function test_only_the_new_event_is_in_a_later_digest(): void
    {
        $host = User::factory()->create();
        $this->trashed($host, 27, ['name' => 'First']);
        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        $this->trashed($host, 26, ['name' => 'Second']);
        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentToTimes($host, HostPurgeWarningNotification::class, 2);
        Notification::assertSentTo($host, HostPurgeWarningNotification::class, fn ($n) => array_column($n->events, 'name') === ['Second']);
    }

    public function test_restoring_and_deleting_again_is_a_new_deletion_and_warned_afresh(): void
    {
        $host = User::factory()->create();
        $event = $this->trashed($host, 27);
        $this->artisan('events:warn-pending-purge')->assertSuccessful();
        $firstKey = $event->purgeWarningKey();

        $event->restore();
        $event->delete();
        Event::withTrashed()->whereKey($event->id)->update(['deleted_at' => now()->subDays(26)]);
        $again = Event::withTrashed()->findOrFail($event->id);

        $this->assertNotSame($firstKey, $again->purgeWarningKey());

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentToTimes($host, HostPurgeWarningNotification::class, 2);
    }

    public function test_the_digest_links_to_the_right_portal_and_reads_correctly(): void
    {
        $host = User::factory()->create();
        $this->trashed($host, 28, ['name' => 'Private Party']);
        $this->trashed($host, 27, ['name' => 'Ticketed Gala'], ticketed: true);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertSentTo($host, HostPurgeWarningNotification::class, function (HostPurgeWarningNotification $n) use ($host): bool {
            $byName = collect($n->events)->keyBy('name');
            $mail = $n->toMail($host);

            return $byName['Private Party']['list_url'] === route('events.index')
                && $byName['Ticketed Gala']['list_url'] === route('public-events.index')
                && $mail->subject === '2 deleted events will be permanently removed soon'
                && $mail->actionUrl === route('events.index') // the soonest event's portal
                && str_contains(implode(' ', array_map('strval', $mail->introLines)), 'Private Party');
        });
    }

    public function test_a_single_event_digest_names_the_date_in_the_subject(): void
    {
        $host = User::factory()->create();
        $event = $this->trashed($host, 27, ['name' => 'Solo']);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        $expected = 'A deleted event will be permanently removed on '.$event->scheduledPurgeDate()->format('M j, Y');
        Notification::assertSentTo($host, HostPurgeWarningNotification::class, fn ($n) => $n->toMail($host)->subject === $expected);
    }

    // ── Who is not emailed ───────────────────────────────────────────────────

    public function test_a_suspended_host_is_skipped_and_their_event_is_then_never_purged(): void
    {
        $host = User::factory()->create(['status' => 'suspended']);
        $event = $this->trashed($host, 45);

        $this->artisan('events:warn-pending-purge')
            ->expectsOutputToContain('skipped 1')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertCount(0, $this->warnLogs());

        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNotNull(Event::withTrashed()->find($event->id), 'No warning on record, so it is kept.');
    }

    public function test_dry_run_reports_without_sending_or_logging(): void
    {
        $host = User::factory()->create(['email' => 'host@example.test']);
        $this->trashed($host, 27, ['name' => 'Would Warn']);

        $this->artisan('events:warn-pending-purge', ['--dry-run' => true])
            ->expectsOutputToContain('host@example.test: Would Warn')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertCount(0, $this->warnLogs());
    }

    public function test_it_sends_nothing_while_purging_is_off(): void
    {
        config(['events.retention.deleted_days' => 0]);
        $this->trashed(User::factory()->create(), 400);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_one_failing_host_does_not_stop_the_others(): void
    {
        $bad = User::factory()->create();
        $good = User::factory()->create();
        $this->trashed($bad, 27);
        $this->trashed($good, 27);

        $real = app(CommunicationService::class);
        $this->mock(CommunicationService::class, function ($mock) use ($bad, $real): void {
            $mock->shouldReceive('sendPurgeWarning')->andReturnUsing(function (User $host, $events) use ($bad, $real): int {
                if ($host->is($bad)) {
                    throw new \RuntimeException('mail down');
                }

                return $real->sendPurgeWarning($host, $events);
            });
        });

        $this->artisan('events:warn-pending-purge')->assertFailed();

        Notification::assertSentTo($good, HostPurgeWarningNotification::class);
        Notification::assertNotSentTo($bad, HostPurgeWarningNotification::class);
    }

    // ── The purge will not delete what nobody was told about ─────────────────

    public function test_an_unwarned_event_past_its_date_is_kept_by_the_purge(): void
    {
        $event = $this->trashed(User::factory()->create(), 90);

        $outcome = app(EventPurgeService::class)->purge($event->id);

        $this->assertSame(PurgeOutcome::SKIPPED, $outcome->status);
        $this->assertSame('not yet warned', $outcome->reason);
        $this->assertNotNull(Event::withTrashed()->find($event->id));
    }

    public function test_the_purge_waits_a_day_after_the_first_warning_even_if_the_event_is_overdue(): void
    {
        $event = $this->trashed(User::factory()->create(), 90);

        // Night one: warn, then purge in the same scheduled run — must not delete.
        $this->artisan('events:warn-pending-purge')->assertSuccessful();
        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNotNull(Event::withTrashed()->find($event->id), 'Warned and deleted in the same night is not notice.');

        // Night two, a day and a bit later: they have had their day.
        $this->travel(Event::PURGE_MIN_NOTICE_HOURS + 1)->hours();
        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNull(Event::withTrashed()->find($event->id));
    }

    public function test_the_normal_path_warns_a_week_ahead_and_purges_on_the_date(): void
    {
        $event = $this->trashed(User::factory()->create(), 23); // purges in 7 days

        $this->artisan('events:warn-pending-purge')->assertSuccessful();
        Notification::assertSentTimes(HostPurgeWarningNotification::class, 1);

        $this->travel(6)->days();
        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNotNull(Event::withTrashed()->find($event->id), 'Not due yet.');

        $this->travel(2)->days();
        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNull(Event::withTrashed()->find($event->id));
    }

    public function test_only_a_sent_or_pending_warning_counts_not_a_failed_one(): void
    {
        $event = $this->trashed(User::factory()->create(), 90);
        $log = NotificationLog::query()->create([
            'event_id' => $event->id, 'channel' => 'email', 'type' => 'event_purge_warning',
            'status' => NotificationLog::STATUS_FAILED, 'idempotency_key' => $event->purgeWarningKey(),
        ]);
        NotificationLog::query()->whereKey($log->id)->update(['created_at' => now()->subDays(3)]);

        $this->assertSame('not yet warned', app(EventPurgeService::class)->purge($event->id)->reason);

        NotificationLog::query()->whereKey($log->id)->update(['status' => NotificationLog::STATUS_PENDING]);
        $this->assertSame(PurgeOutcome::PURGED, app(EventPurgeService::class)->purge($event->id)->status);
    }

    public function test_a_warning_for_an_earlier_deletion_does_not_cover_a_later_one(): void
    {
        $event = $this->trashed(User::factory()->create(), 90);
        $oldKey = $event->purgeWarningKey();
        $log = NotificationLog::query()->create([
            'event_id' => $event->id, 'channel' => 'email', 'type' => 'event_purge_warning',
            'status' => NotificationLog::STATUS_SENT, 'idempotency_key' => $oldKey,
        ]);
        NotificationLog::query()->whereKey($log->id)->update(['created_at' => now()->subDays(60)]);

        // Restored and deleted again: same event, new deletion.
        $event->restore();
        $event->delete();
        Event::withTrashed()->whereKey($event->id)->update(['deleted_at' => now()->subDays(40)]);

        $this->assertSame('not yet warned', app(EventPurgeService::class)->purge($event->id)->reason);
    }

    public function test_pre_release_trash_is_warned_a_week_before_a_full_window_from_the_release(): void
    {
        config(['events.retention.starts_at' => now()->toDateString()]);
        $event = $this->trashed(User::factory()->create(), 400);

        $this->artisan('events:warn-pending-purge')->assertSuccessful();
        Notification::assertNothingSent(); // release + 30 days is 30 days away

        $this->travel(23)->days();
        $this->artisan('events:warn-pending-purge')->assertSuccessful();
        Notification::assertSentTimes(HostPurgeWarningNotification::class, 1);

        $this->travel(8)->days();
        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNull(Event::withTrashed()->find($event->id));
    }
}
