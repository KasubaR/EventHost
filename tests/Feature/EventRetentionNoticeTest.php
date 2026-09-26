<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\TicketOrder;
use App\Models\User;
use App\Support\EventRetentionNotice;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 2 of plans/event-retention.md: telling people what will happen to a
 * deleted event — countdown, "kept for payment records", the delete flash, the API
 * field and the admin pages. The purge itself is EventPurgeTest.
 */
class EventRetentionNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'events.retention.deleted_days' => 30,
            'events.retention.starts_at' => now()->subDays(120)->toDateString(),
        ]);
    }

    private function trashed(User $owner, int $daysAgo, bool $ticketed = false, array $attributes = []): Event
    {
        $event = ($ticketed ? Event::factory()->ticketed() : Event::factory())->for($owner)->create($attributes);
        $event->delete();
        Event::withTrashed()->whereKey($event->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

        return Event::withTrashed()->findOrFail($event->id);
    }

    // ── The notice itself ────────────────────────────────────────────────────

    public function test_the_label_counts_whole_days_rounded_up(): void
    {
        $owner = User::factory()->create();

        $this->assertSame('Permanently deleted in 20 days', EventRetentionNotice::for($this->trashed($owner, 10))->label);
        $this->assertSame('Permanently deleted in 1 day', EventRetentionNotice::for($this->trashed($owner, 29))->label);
        $this->assertSame('Permanently deleted soon', EventRetentionNotice::for($this->trashed($owner, 45))->label);

        // 12 hours left must still read "1 day", never "0 days" while it is restorable.
        $almost = $this->trashed($owner, 29);
        Event::withTrashed()->whereKey($almost->id)->update(['deleted_at' => now()->subDays(29)->subHours(12)]);
        $this->assertSame('Permanently deleted in 1 day', EventRetentionNotice::for(Event::withTrashed()->findOrFail($almost->id))->label);
    }

    public function test_there_is_no_notice_when_purging_is_off_or_the_event_is_live(): void
    {
        $owner = User::factory()->create();
        $deleted = $this->trashed($owner, 10);

        config(['events.retention.deleted_days' => 0]);
        $this->assertNull(EventRetentionNotice::for($deleted));

        config(['events.retention.deleted_days' => 30]);
        $this->assertNull(EventRetentionNotice::for(Event::factory()->for($owner)->create()));
    }

    public function test_an_event_that_has_taken_money_is_marked_kept_with_no_date(): void
    {
        $event = $this->trashed(User::factory()->create(), 90, ticketed: true);
        TicketOrder::factory()->for($event)->paid()->create();

        $notice = EventRetentionNotice::for($event);

        $this->assertTrue($notice->isKept());
        $this->assertNull($notice->purgeAt);
        $this->assertSame('Kept for payment records', $notice->label);
    }

    public function test_pre_release_trash_shows_the_date_a_full_window_after_the_release(): void
    {
        config(['events.retention.starts_at' => now()->toDateString()]);
        $event = $this->trashed(User::factory()->create(), 400);

        $this->assertEquals(now()->startOfDay()->addDays(30), EventRetentionNotice::for($event)->purgeAt);
        $this->assertSame('Permanently deleted in 30 days', EventRetentionNotice::for($event)->label);
    }

    // ── The host's Recently deleted list ─────────────────────────────────────

    public function test_recently_deleted_shows_the_countdown_and_the_section_note_on_both_portals(): void
    {
        $owner = User::factory()->create();
        $this->trashed($owner, 12, attributes: ['name' => 'Kabwe Reunion']);
        $this->trashed($owner, 12, ticketed: true, attributes: ['name' => 'Gala Night']);

        $this->actingAs($owner)->get(route('events.index'))
            ->assertOk()
            ->assertSee('Kabwe Reunion')
            ->assertSee('Permanently deleted in 18 days')
            ->assertSee('Deleted events can be restored for 30 days, then they are removed permanently.');

        $this->actingAs($owner)->get(route('public-events.index'))
            ->assertOk()
            ->assertSee('Gala Night')
            ->assertSee('Permanently deleted in 18 days');
    }

    public function test_an_exempt_event_says_kept_for_payment_records_and_shows_no_countdown(): void
    {
        $owner = User::factory()->create();
        $event = $this->trashed($owner, 90, attributes: ['name' => 'Wedding With Pledges']);
        EventContribution::factory()->for($event)->create(['amount_paid' => '40.00']);

        $this->actingAs($owner)->get(route('events.index'))
            ->assertOk()
            ->assertSee('Wedding With Pledges')
            ->assertSee('Kept for payment records')
            ->assertDontSee('Permanently deleted in')
            ->assertDontSee('Permanently deleted soon');
    }

    public function test_nothing_changes_on_the_page_while_purging_is_off(): void
    {
        config(['events.retention.deleted_days' => 0]);
        $owner = User::factory()->create();
        $this->trashed($owner, 12, attributes: ['name' => 'Quiet Event']);

        $this->actingAs($owner)->get(route('events.index'))
            ->assertOk()
            ->assertSee('Quiet Event')
            ->assertSee('Recently deleted')
            ->assertDontSee('Permanently deleted')
            ->assertDontSee('can be restored for');
    }

    public function test_the_delete_flash_states_the_window_only_when_there_is_one(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->withSession(['status' => 'event-deleted'])->get(route('events.index'))
            ->assertSee('Event deleted. You can restore it from Recently deleted below within 30 days.');

        config(['events.retention.deleted_days' => 0]);

        $this->actingAs($owner)->withSession(['status' => 'event-deleted'])->get(route('events.index'))
            ->assertSee('Event deleted. You can restore it from Recently deleted below.')
            ->assertDontSee('within 30 days');
    }

    // ── The API ──────────────────────────────────────────────────────────────

    public function test_the_api_list_carries_purge_at_and_retained_for_records(): void
    {
        $owner = User::factory()->create();
        $due = $this->trashed($owner, 10);
        $kept = $this->trashed($owner, 10, ticketed: true);
        TicketOrder::factory()->for($kept)->paid()->create();
        $live = Event::factory()->for($owner)->published()->create();

        Sanctum::actingAs($owner);
        $rows = collect($this->getJson('/api/v1/host/events?status=deleted')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame($due->deleted_at->copy()->addDays(30)->toIso8601String(), $rows[$due->id]['purge_at']);
        $this->assertFalse($rows[$due->id]['retained_for_records']);
        $this->assertNull($rows[$kept->id]['purge_at']);
        $this->assertTrue($rows[$kept->id]['retained_for_records']);

        $liveRow = collect($this->getJson('/api/v1/host/events')->json('data'))->firstWhere('id', $live->id);
        $this->assertNull($liveRow['purge_at']);
        $this->assertFalse($liveRow['retained_for_records']);
    }

    public function test_the_api_fields_are_additive_and_null_while_purging_is_off(): void
    {
        config(['events.retention.deleted_days' => 0]);
        $owner = User::factory()->create();
        $event = $this->trashed($owner, 10);

        Sanctum::actingAs($owner);
        $row = collect($this->getJson('/api/v1/host/events?status=deleted')->json('data'))->firstWhere('id', $event->id);

        $this->assertNotNull($row['deleted_at']);
        $this->assertNull($row['purge_at']);
        $this->assertFalse($row['retained_for_records']);
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function test_the_admin_pages_show_when_a_deleted_event_goes_and_when_one_is_kept(): void
    {
        $owner = User::factory()->create();
        $due = $this->trashed($owner, 10, attributes: ['name' => 'Countdown Event']);
        $kept = $this->trashed($owner, 90, ticketed: true, attributes: ['name' => 'Ledger Event']);
        TicketOrder::factory()->for($kept)->paid()->create();

        $this->seed(RolePermissionSeeder::class);
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $this->actingAs($admin, 'admin')->get(route('admin.events.index'))
            ->assertOk()
            ->assertSee('Countdown Event')
            ->assertSee('Permanently deleted in 20 days')
            ->assertSee('Kept for payment records');

        $this->actingAs($admin, 'admin')->get(route('admin.events.show', $due))
            ->assertOk()
            ->assertSee('Permanently deleted in 20 days')
            ->assertSee($due->deleted_at->copy()->addDays(30)->format('M j, Y'));

        $this->actingAs($admin, 'admin')->get(route('admin.events.show', $kept))
            ->assertOk()
            ->assertSee('Retained: payment records');
    }
}
