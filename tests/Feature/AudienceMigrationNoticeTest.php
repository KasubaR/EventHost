<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6 item 3 of plans/public-private-portals.md: a one-time notice for
 * hosts whose invitation event was public before the portal split existed,
 * so it silently moved into the Public portal at the 2026-09-22 audience
 * backfill. The backfill migration (add_audience_migration_notice_to_events_table)
 * reset audience_migration_notice_seen_at to NULL only on the exact rows the
 * original 2026-09-22 audience backfill flipped to public — that one-time
 * data reset isn't something a fresh RefreshDatabase schema can exercise (a
 * fresh migrate has no pre-existing rows to backfill), so these tests cover
 * the model/controller/view behavior the column drives instead.
 */
class AudienceMigrationNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_newly_created_event_never_needs_the_notice(): void
    {
        $event = Event::factory()->publicAudience()->create();

        $this->assertFalse($event->needsAudienceMigrationNotice());
    }

    public function test_an_event_reset_to_null_needs_the_notice_until_dismissed(): void
    {
        $event = Event::factory()->publicAudience()->create();
        $event->forceFill(['audience_migration_notice_seen_at' => null])->save();

        $this->assertTrue($event->fresh()->needsAudienceMigrationNotice());

        $event->dismissAudienceMigrationNotice();

        $this->assertFalse($event->fresh()->needsAudienceMigrationNotice());
    }

    public function test_public_dashboard_shows_the_banner_only_for_events_needing_the_notice(): void
    {
        $user = User::factory()->create();
        $migrated = Event::factory()->for($user)->publicAudience()->create(['name' => 'Old Public Wedding']);
        $migrated->forceFill(['audience_migration_notice_seen_at' => null])->save();
        Event::factory()->for($user)->publicAudience()->create(['name' => 'Freshly Created Concert']);

        $response = $this->actingAs($user)->get(route('public-dashboard'));

        $response->assertOk()
            ->assertSee('moved to this portal')
            ->assertSee('Old Public Wedding')
            ->assertDontSee('Freshly Created Concert');
    }

    public function test_public_dashboard_hides_the_banner_when_nothing_needs_it(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->publicAudience()->create();

        $this->actingAs($user)
            ->get(route('public-dashboard'))
            ->assertOk()
            ->assertDontSee('moved to this portal');
    }

    public function test_owner_can_dismiss_the_notice(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->publicAudience()->create();
        $event->forceFill(['audience_migration_notice_seen_at' => null])->save();

        $this->actingAs($user)
            ->patch(route('events.audience-migration-notice.dismiss', $event))
            ->assertRedirect();

        $this->assertFalse($event->fresh()->needsAudienceMigrationNotice());
    }

    public function test_non_owner_cannot_dismiss_the_notice(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->publicAudience()->create();
        $event->forceFill(['audience_migration_notice_seen_at' => null])->save();

        $this->actingAs($stranger)
            ->patch(route('events.audience-migration-notice.dismiss', $event))
            ->assertForbidden();

        $this->assertTrue($event->fresh()->needsAudienceMigrationNotice());
    }
}
