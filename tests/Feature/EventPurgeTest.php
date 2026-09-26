<?php

namespace Tests\Feature;

use App\Enums\TicketOrderStatus;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventPhoto;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\NotificationLog;
use App\Models\Review;
use App\Models\Rsvp;
use App\Models\TicketOrder;
use App\Models\User;
use App\Services\EventPurgeService;
use App\Services\GuestPassFileCache;
use App\Support\PurgeOutcome;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Phase 1 of plans/event-retention.md: events:purge-deleted, the exemption for
 * events that have taken money, and the launch-date safeguard.
 */
class EventPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Purging on, with a release date far enough back that ordinary trash is eligible.
        config([
            'events.retention.deleted_days' => 30,
            'events.retention.starts_at' => now()->subDays(120)->toDateString(),
        ]);
    }

    /**
     * A deleted event, with deleted_at set directly so the age is exact. Warned about two
     * days ago by default — the purge refuses anything unwarned (EventPurgeWarningTest
     * covers that), so the tests in this file that are about something else start warned.
     */
    private function trashed(int $daysAgo, array $attributes = [], bool $ticketed = false, bool $warned = true): Event
    {
        $factory = $ticketed ? Event::factory()->ticketed() : Event::factory();
        $event = $factory->for(User::factory()->create())->create($attributes);

        $event->delete();
        Event::withTrashed()->whereKey($event->id)->update(['deleted_at' => now()->subDays($daysAgo)]);
        $event = Event::withTrashed()->findOrFail($event->id);

        if ($warned) {
            $log = NotificationLog::query()->create([
                'event_id' => $event->id,
                'channel' => 'email',
                'type' => 'event_purge_warning',
                'status' => NotificationLog::STATUS_SENT,
                'idempotency_key' => $event->purgeWarningKey(),
            ]);
            NotificationLog::query()->whereKey($log->id)->update(['created_at' => now()->subDays(2)]);
        }

        return $event;
    }

    private function gone(Event $event): bool
    {
        return ! Event::withTrashed()->whereKey($event->id)->exists();
    }

    // ── What gets purged ─────────────────────────────────────────────────────

    public function test_an_event_past_the_window_is_removed_with_everything_hanging_off_it(): void
    {
        $event = $this->trashed(31);
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->for($guest)->create();
        GuestGroup::factory()->for($event)->create();
        EventTable::factory()->for($event)->create();
        EventPhoto::factory()->for($event)->create();

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertTrue($this->gone($event));
        $this->assertSame(0, Guest::query()->where('event_id', $event->id)->count());
        $this->assertSame(0, Rsvp::query()->where('event_id', $event->id)->count());
        $this->assertSame(0, GuestGroup::query()->where('event_id', $event->id)->count());
        $this->assertSame(0, EventTable::query()->where('event_id', $event->id)->count());
        $this->assertSame(0, EventPhoto::query()->where('event_id', $event->id)->count());
    }

    public function test_an_event_inside_the_window_and_a_live_event_are_untouched(): void
    {
        $recent = $this->trashed(29);
        $live = Event::factory()->for(User::factory()->create())->create();

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertNotNull(Event::withTrashed()->find($recent->id));
        $this->assertNotNull(Event::find($live->id));
    }

    public function test_purging_is_off_when_the_retention_days_are_zero(): void
    {
        config(['events.retention.deleted_days' => 0]);
        $old = $this->trashed(500);

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertNotNull(Event::withTrashed()->find($old->id));
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $old = $this->trashed(40);
        Guest::factory()->for($old)->create();

        $this->artisan('events:purge-deleted', ['--dry-run' => true])
            ->expectsOutputToContain('would purge: 1')
            ->assertSuccessful();

        $this->assertNotNull(Event::withTrashed()->find($old->id));
        $this->assertSame(1, Guest::query()->where('event_id', $old->id)->count());
    }

    public function test_limit_caps_how_many_are_purged_in_one_run(): void
    {
        $a = $this->trashed(40);
        $b = $this->trashed(41);
        $c = $this->trashed(42);

        $this->artisan('events:purge-deleted', ['--limit' => 2])->assertSuccessful();

        $remaining = collect([$a, $b, $c])->filter(fn (Event $e) => ! $this->gone($e))->count();
        $this->assertSame(1, $remaining);
    }

    // ── Events that have taken money are never purged ────────────────────────

    public function test_ticketed_events_with_money_movement_are_kept(): void
    {
        foreach ([TicketOrderStatus::Paid, TicketOrderStatus::Refunded, TicketOrderStatus::PendingPayment, TicketOrderStatus::PaymentProcessing] as $status) {
            $event = $this->trashed(90, ticketed: true);
            TicketOrder::factory()->for($event)->create(['status' => $status]);

            $this->artisan('events:purge-deleted')->assertSuccessful();

            $this->assertFalse($this->gone($event), "A {$status->value} order must protect the event.");
            $this->assertNull($event->fresh()?->purgeAt() ?? Event::withTrashed()->find($event->id)->purgeAt());
        }
    }

    public function test_ticketed_events_whose_orders_never_took_money_are_purged(): void
    {
        foreach ([TicketOrderStatus::Failed, TicketOrderStatus::Cancelled, TicketOrderStatus::Expired] as $status) {
            $event = $this->trashed(90, ticketed: true);
            TicketOrder::factory()->for($event)->create(['status' => $status]);

            $this->artisan('events:purge-deleted')->assertSuccessful();

            $this->assertTrue($this->gone($event), "A {$status->value} order took no money and must not protect the event.");
        }
    }

    public function test_contribution_payments_that_took_money_protect_the_event(): void
    {
        $cases = [
            'completed payment' => fn (EventContribution $c) => $c->payments()->create($this->payment('completed')),
            'refunded payment' => fn (EventContribution $c) => $c->payments()->create($this->payment('refunded')),
            'amount paid' => fn (EventContribution $c) => $c->forceFill(['amount_paid' => '40.00'])->save(),
            'recent pending payment' => fn (EventContribution $c) => $c->payments()->create($this->payment('pending')),
            'recent processing payment' => fn (EventContribution $c) => $c->payments()->create($this->payment('processing')),
        ];

        foreach ($cases as $label => $arrange) {
            $event = $this->trashed(90);
            $arrange(EventContribution::factory()->for($event)->create());

            $this->artisan('events:purge-deleted')->assertSuccessful();

            $this->assertFalse($this->gone($event), "A contribution with a {$label} must protect the event.");
        }
    }

    public function test_an_abandoned_or_failed_contribution_payment_does_not_protect_the_event(): void
    {
        $abandoned = $this->trashed(90);
        $contribution = EventContribution::factory()->for($abandoned)->create();
        $stale = $contribution->payments()->create($this->payment('pending'));
        $stale->forceFill(['created_at' => now()->subDays(Event::CONTRIBUTION_IN_FLIGHT_DAYS + 3)])->save();

        $failed = $this->trashed(90);
        EventContribution::factory()->for($failed)->create()->payments()->create($this->payment('failed'));

        $noPayments = $this->trashed(90);
        EventContribution::factory()->for($noPayments)->create();

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertTrue($this->gone($abandoned), 'Nothing expires a pending contribution payment; it must not shield an event forever.');
        $this->assertTrue($this->gone($failed));
        $this->assertTrue($this->gone($noPayments));
    }

    /** @return array<string, mixed> */
    private function payment(string $status): array
    {
        return [
            'payment_method' => 'mobile_money',
            'amount' => '50.00',
            'currency' => 'ZMW',
            'status' => $status,
            'payment_reference' => 'CTBP-'.uniqid(),
        ];
    }

    public function test_money_that_lands_after_selection_is_caught_by_the_locked_recheck(): void
    {
        $event = $this->trashed(90, ticketed: true);
        // The order settles after the command selected the event but before it acts.
        TicketOrder::factory()->for($event)->paid()->create();

        $outcome = app(EventPurgeService::class)->purge($event->id);

        $this->assertSame(PurgeOutcome::SKIPPED, $outcome->status);
        $this->assertSame('kept for payment records', $outcome->reason);
        $this->assertFalse($this->gone($event));
    }

    public function test_an_event_restored_before_the_lock_is_skipped_and_kept(): void
    {
        $event = $this->trashed(90);
        $event->restore();

        $outcome = app(EventPurgeService::class)->purge($event->id);

        $this->assertSame(PurgeOutcome::SKIPPED, $outcome->status);
        $this->assertNotNull(Event::find($event->id));
    }

    public function test_an_event_still_inside_the_window_is_skipped_by_the_service_itself(): void
    {
        $event = $this->trashed(5);

        $this->assertSame(PurgeOutcome::SKIPPED, app(EventPurgeService::class)->purge($event->id)->status);
        $this->assertNotNull(Event::withTrashed()->find($event->id));
    }

    // ── What survives, and what must not be left behind ──────────────────────

    public function test_a_published_review_outlives_its_event_with_the_author_snapshot_intact(): void
    {
        $event = $this->trashed(60);
        $review = Review::factory()->featured()->create([
            'user_id' => $event->user_id,
            'event_id' => $event->id,
            'author_name' => 'Chanda Mwila',
            'author_context' => 'Wedding · Lusaka',
        ]);

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertTrue($this->gone($event));
        $review->refresh();
        $this->assertNull($review->event_id);
        $this->assertSame('Chanda Mwila', $review->author_name);
        $this->assertTrue($review->is_featured);
    }

    public function test_notification_logs_go_with_the_event_but_ledger_rows_stay(): void
    {
        $event = $this->trashed(60);
        NotificationLog::query()->create([
            'event_id' => $event->id, 'channel' => 'email', 'type' => 'rsvp_confirmation',
            'status' => NotificationLog::STATUS_SENT, 'response' => 'delivered to a guest',
        ]);
        $credit = CreditTransaction::factory()->create(['event_id' => $event->id]);

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertSame(0, NotificationLog::query()->count(), 'Logs must not outlive their guests, orphaned.');
        $this->assertNull($credit->fresh()->event_id);
    }

    public function test_files_are_removed_after_the_row_is_gone(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $event = $this->trashed(60, ['cover_image' => 'covers/one.webp']);
        $photo = EventPhoto::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_token' => 'purge-token-1']);

        Storage::disk('public')->put('covers/one.webp', 'x');
        Storage::disk('public')->put($photo->path, 'x');
        Storage::disk('public')->put($photo->thumbnail_path, 'x');
        Storage::disk('public')->put("invitation-gallery/{$event->id}/a.webp", 'x');
        Storage::disk('public')->put("invitation-hero/{$event->id}/h.webp", 'x');
        Storage::disk('local')->put(GuestPassFileCache::PDF.'/purge-token-1/abc.pdf', 'x');
        Storage::disk('local')->put(GuestPassFileCache::IMAGE.'/purge-token-1/abc.png', 'x');

        // Another event's files must be left alone.
        $other = Event::factory()->for(User::factory()->create())->create(['cover_image' => 'covers/keep.webp']);
        Storage::disk('public')->put('covers/keep.webp', 'x');
        Storage::disk('public')->put("invitation-gallery/{$other->id}/keep.webp", 'x');

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertTrue($this->gone($event));
        foreach (['covers/one.webp', $photo->path, $photo->thumbnail_path] as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        Storage::disk('public')->assertMissing("invitation-gallery/{$event->id}/a.webp");
        Storage::disk('public')->assertMissing("invitation-hero/{$event->id}/h.webp");
        Storage::disk('local')->assertMissing(GuestPassFileCache::PDF.'/purge-token-1/abc.pdf');
        Storage::disk('local')->assertMissing(GuestPassFileCache::IMAGE.'/purge-token-1/abc.png');
        $this->assertNotNull($guest);

        Storage::disk('public')->assertExists('covers/keep.webp');
        Storage::disk('public')->assertExists("invitation-gallery/{$other->id}/keep.webp");
    }

    public function test_unsafe_stored_paths_are_never_handed_to_the_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('outside.txt', 'x');

        $event = $this->trashed(60, ['cover_image' => '../outside.txt']);

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertTrue($this->gone($event));
        Storage::disk('public')->assertExists('outside.txt');
    }

    public function test_a_file_that_cannot_be_deleted_is_logged_and_does_not_undo_the_purge(): void
    {
        $event = $this->trashed(60, ['cover_image' => 'covers/stuck.webp']);

        $disk = Mockery::mock(FilesystemAdapter::class)->shouldIgnoreMissing();
        $disk->shouldReceive('delete')->andThrow(new \RuntimeException('disk unavailable'));
        Storage::set('public', $disk);

        $outcome = app(EventPurgeService::class)->purge($event->id);

        $this->assertSame(PurgeOutcome::PURGED, $outcome->status);
        $this->assertTrue($this->gone($event));
    }

    // ── The release date ─────────────────────────────────────────────────────

    public function test_trash_that_predates_the_release_is_not_purged_until_a_full_window_after_it(): void
    {
        config(['events.retention.starts_at' => now()->toDateString()]);
        $old = $this->trashed(400);

        $this->assertEquals(now()->startOfDay()->addDays(30), $old->scheduledPurgeDate());

        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertNotNull(Event::withTrashed()->find($old->id), 'Pre-release trash must get a full window from the release.');

        $this->travel(31)->days();
        $this->artisan('events:purge-deleted')->assertSuccessful();
        $this->assertTrue($this->gone($old));
    }

    public function test_trash_created_after_the_release_uses_its_own_deleted_at(): void
    {
        config(['events.retention.starts_at' => now()->subDays(50)->toDateString()]);
        $event = $this->trashed(10);

        $this->assertEquals($event->deleted_at->copy()->addDays(30), $event->scheduledPurgeDate());
    }

    public function test_it_refuses_to_run_against_old_trash_when_the_release_date_is_unset(): void
    {
        config(['events.retention.starts_at' => null]);
        $old = $this->trashed(400);

        $this->artisan('events:purge-deleted')
            ->expectsOutputToContain('EVENT_TRASH_RETENTION_STARTS_AT is not set')
            ->assertFailed();

        $this->assertNotNull(Event::withTrashed()->find($old->id));
    }

    public function test_an_unparseable_release_date_is_treated_as_unset(): void
    {
        config(['events.retention.starts_at' => 'not-a-date']);
        $old = $this->trashed(400);

        $this->artisan('events:purge-deleted')
            ->expectsOutputToContain('is not a valid date')
            ->assertFailed();

        $this->assertNotNull(Event::withTrashed()->find($old->id));
    }

    public function test_an_unset_release_date_does_not_block_a_fresh_install(): void
    {
        config(['events.retention.starts_at' => null]);
        $recent = $this->trashed(3);

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertNotNull(Event::withTrashed()->find($recent->id));
    }

    public function test_allow_backlog_is_the_deliberate_override(): void
    {
        config(['events.retention.starts_at' => null]);
        $old = $this->trashed(400);

        $this->artisan('events:purge-deleted', ['--allow-backlog' => true])->assertSuccessful();

        $this->assertTrue($this->gone($old));
    }

    public function test_dry_run_is_never_refused_and_still_deletes_nothing(): void
    {
        config(['events.retention.starts_at' => null]);
        $old = $this->trashed(400);

        $this->artisan('events:purge-deleted', ['--dry-run' => true])
            ->expectsOutputToContain('would purge: 1')
            ->assertSuccessful();

        $this->assertNotNull(Event::withTrashed()->find($old->id));
    }

    // ── The date the UI will show ────────────────────────────────────────────

    public function test_purge_at_is_the_scheduled_date_and_null_for_exempt_or_disabled_events(): void
    {
        $event = $this->trashed(10);
        $this->assertEquals($event->deleted_at->copy()->addDays(30), $event->purgeAt());

        $paid = $this->trashed(10, ticketed: true);
        TicketOrder::factory()->for($paid)->paid()->create();
        $this->assertNull($paid->purgeAt());

        $this->assertNull(Event::factory()->for(User::factory()->create())->create()->purgeAt(), 'A live event has no purge date.');

        config(['events.retention.deleted_days' => 0]);
        $this->assertNull($event->purgeAt());
    }

    public function test_the_card_date_and_the_job_agree_at_the_boundary(): void
    {
        $due = $this->trashed(30);
        $notYet = $this->trashed(29);

        $this->assertTrue($due->purgeAt()->lte(now()->addSecond()), 'A 30-day-old event is due now.');
        $this->assertTrue($notYet->purgeAt()->isFuture());

        $this->artisan('events:purge-deleted')->assertSuccessful();

        $this->assertTrue($this->gone($due));
        $this->assertFalse($this->gone($notYet));
    }
}
