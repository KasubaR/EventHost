<?php

namespace Tests\Feature;

use App\Enums\EventAudience;
use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 of plans/public-private-portals.md: `audience` exists, is derived
 * from the legacy flags while is_public is still an input, and can be assigned
 * explicitly, in which case it drives is_public.
 */
class EventAudienceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The backfill rule, one row per combination. The migration applies the same
     * rule in SQL; EventAudience::derive() is the single PHP statement of it.
     */
    public function test_derive_matrix(): void
    {
        $this->assertSame(EventAudience::Private, EventAudience::derive(EventProductKind::Invitation, false));
        $this->assertSame(EventAudience::Public, EventAudience::derive(EventProductKind::Invitation, true));
        $this->assertSame(EventAudience::Public, EventAudience::derive(EventProductKind::Ticketed, true));
        // A ticketed event is public even if is_public is somehow false.
        $this->assertSame(EventAudience::Public, EventAudience::derive(EventProductKind::Ticketed, false));
        $this->assertSame(EventAudience::Private, EventAudience::derive(null, false));
    }

    public function test_private_invitation_event_is_derived_private(): void
    {
        $event = Event::factory()->create(['is_public' => false]);

        $this->assertSame(EventAudience::Private, $event->fresh()->audience);
        $this->assertTrue($event->fresh()->isPrivate());
        $this->assertFalse($event->fresh()->isPublicAudience());
    }

    public function test_public_invitation_event_is_derived_public(): void
    {
        $event = Event::factory()->create(['is_public' => true]);

        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
    }

    public function test_ticketed_event_is_derived_public(): void
    {
        $event = Event::factory()->ticketed()->create();

        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
        $this->assertTrue($event->fresh()->is_public);
    }

    public function test_toggling_is_public_through_the_legacy_checkbox_moves_the_audience(): void
    {
        $event = Event::factory()->create(['is_public' => false]);
        $this->assertSame(EventAudience::Private, $event->fresh()->audience);

        $event->update(['is_public' => true]);
        $this->assertSame(EventAudience::Public, $event->fresh()->audience);

        $event->update(['is_public' => false]);
        $this->assertSame(EventAudience::Private, $event->fresh()->audience);
    }

    public function test_explicit_private_audience_forces_is_public_false(): void
    {
        $event = Event::factory()->privateAudience()->create(['is_public' => true]);

        $this->assertSame(EventAudience::Private, $event->fresh()->audience);
        $this->assertFalse($event->fresh()->is_public);
    }

    public function test_explicit_public_audience_forces_is_public_true(): void
    {
        $event = Event::factory()->publicAudience()->create(['is_public' => false]);

        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
        $this->assertTrue($event->fresh()->is_public);
    }

    public function test_assigning_audience_on_an_existing_event_drives_is_public(): void
    {
        $event = Event::factory()->create(['is_public' => true]);

        $event->update(['audience' => EventAudience::Private]);

        $this->assertFalse($event->fresh()->is_public);
        $this->assertSame(EventAudience::Private, $event->fresh()->audience);
    }

    public function test_a_ticketed_event_cannot_be_private(): void
    {
        $this->expectException(\LogicException::class);

        Event::factory()->ticketed()->privateAudience()->create();
    }

    public function test_ticketed_event_saved_without_is_public_is_still_public_and_listed(): void
    {
        // The desync: nothing touches `audience`, so the derive branch ran, set
        // audience = public, and left is_public = false, which
        // scopePubliclyListed() then filtered out.
        $event = Event::factory()->ticketed()->published()->create(['is_public' => false]);

        $fresh = $event->fresh();
        $this->assertSame(EventAudience::Public, $fresh->audience);
        $this->assertTrue($fresh->is_public);
        $this->assertTrue(Event::query()->publiclyListed()->whereKey($event->id)->exists());
    }

    public function test_flipping_an_existing_row_to_ticketed_forces_it_public(): void
    {
        $event = Event::factory()->published()->privateAudience()->create();
        $this->assertFalse($event->fresh()->is_public);

        $event->update(['product_kind' => EventProductKind::Ticketed]);

        $fresh = $event->fresh();
        $this->assertSame(EventAudience::Public, $fresh->audience);
        $this->assertTrue($fresh->is_public);
    }

    public function test_a_ticketed_event_cannot_be_flipped_to_private_later(): void
    {
        $event = Event::factory()->ticketed()->create();

        try {
            $event->update(['audience' => EventAudience::Private]);
            $this->fail('Expected a LogicException.');
        } catch (\LogicException) {
            // expected
        }

        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
        $this->assertTrue($event->fresh()->is_public);
    }

    public function test_clearing_is_public_on_a_ticketed_event_is_ignored(): void
    {
        $event = Event::factory()->ticketed()->create();

        $event->update(['is_public' => false]);

        $this->assertTrue($event->fresh()->is_public);
        $this->assertSame(EventAudience::Public, $event->fresh()->audience);
    }

    public function test_a_row_where_the_flags_disagree_is_not_publicly_listed(): void
    {
        // Raw updates skip the saving hook, which is the only way the two can drift.
        $audiencePublicOnly = Event::factory()->published()->publicAudience()->create();
        $flagPublicOnly = Event::factory()->published()->privateAudience()->create();
        $agree = Event::factory()->published()->publicAudience()->create();

        DB::table('events')->where('id', $audiencePublicOnly->id)->update(['is_public' => false]);
        DB::table('events')->where('id', $flagPublicOnly->id)->update(['is_public' => true]);

        $this->assertEqualsCanonicalizing(
            [$agree->id],
            Event::query()->publiclyListed()->pluck('id')->all()
        );
    }

    public function test_for_audience_scope_partitions_events(): void
    {
        $user = User::factory()->create();
        $private = Event::factory()->for($user)->privateAudience()->create();
        $public = Event::factory()->for($user)->publicAudience()->create();
        $ticketed = Event::factory()->for($user)->ticketed()->create();

        $this->assertEqualsCanonicalizing(
            [$private->id],
            Event::query()->forAudience(EventAudience::Private)->pluck('id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$public->id, $ticketed->id],
            Event::query()->forAudience(EventAudience::Public)->pluck('id')->all()
        );
    }

    public function test_audience_stays_in_step_with_publicly_listed(): void
    {
        // scopePubliclyListed() reads is_public; audience must never disagree with it.
        Event::factory()->published()->privateAudience()->create();
        Event::factory()->published()->publicAudience()->create();
        Event::factory()->published()->ticketed()->create();

        $this->assertSame(
            Event::query()->publiclyListed()->count(),
            Event::query()->forAudience(EventAudience::Public)->where('is_published', true)->count()
        );
        $this->assertSame(0, Event::query()->forAudience(EventAudience::Private)->where('is_public', true)->count());
    }
}
