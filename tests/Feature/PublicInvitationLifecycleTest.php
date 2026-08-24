<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\EventProductKind;
use App\Enums\TicketingStatus;
use App\Models\Admin;
use App\Models\Event;
use App\Models\EventSlugRedirect;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\TicketOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function liveEvent(array $overrides = []): Event
    {
        return Event::factory()->published()->create(array_merge([
            'user_id' => User::factory(),
            'is_public' => true,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'slug' => 'john-mary',
        ], $overrides));
    }

    public function test_live_published_public_invitation_renders(): void
    {
        $event = $this->liveEvent(['name' => 'John and Mary Wedding']);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('John and Mary Wedding', escape: false);
    }

    public function test_past_event_shows_ended_status_page(): void
    {
        $event = $this->liveEvent([
            'event_date' => now()->subMonth()->format('Y-m-d'),
            'name' => 'Past Party',
        ]);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Event has ended', escape: false)
            ->assertSee('This event has ended.', escape: false)
            ->assertSee('Past Party', escape: false)
            ->assertDontSee('Will you be attending', escape: false);

        $this->assertSame(0, $event->fresh()->invitation_views_count);
    }

    public function test_paused_invitation_shows_unavailable_and_resume_restores_it(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'name' => 'Paused Bash']);

        $this->actingAs($user)
            ->patch(route('events.pause', $event))
            ->assertRedirect();

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Invitation unavailable', escape: false)
            ->assertSee('temporarily unavailable', escape: false);

        $this->assertSame(0, $event->fresh()->invitation_views_count);

        $this->actingAs($user)
            ->patch(route('events.resume', $event))
            ->assertRedirect();

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Paused Bash', escape: false)
            ->assertDontSee('temporarily unavailable', escape: false);
    }

    public function test_cancelled_event_shows_cancelled_page_and_reopen_restores_it(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'name' => 'Cancelled Gala']);

        $this->actingAs($user)
            ->patch(route('events.cancel', $event))
            ->assertRedirect();

        $this->assertNotNull($event->fresh()->cancelled_at);
        $this->assertNull($event->fresh()->invitation_paused_at);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Event cancelled', escape: false)
            ->assertSee('This event has been cancelled.', escape: false);

        $this->actingAs($user)
            ->patch(route('events.uncancel', $event))
            ->assertRedirect();

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee('Cancelled Gala', escape: false)
            ->assertDontSee('This event has been cancelled.', escape: false);
    }

    public function test_soft_deleted_event_shows_gone_page_and_restore_brings_it_back(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'name' => 'Gone Event', 'slug' => 'gone-event']);

        $this->actingAs($user)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'));

        $this->assertSoftDeleted('events', ['id' => $event->id]);

        $this->get(route('events.public', 'gone-event'))
            ->assertOk()
            ->assertSee('Invitation no longer available', escape: false);

        $this->actingAs($user)
            ->post(route('events.restore', $event))
            ->assertRedirect(route('events.show', $event));

        $this->assertNull($event->fresh()->deleted_at);

        $this->get(route('events.public', 'gone-event'))
            ->assertOk()
            ->assertSee('Gone Event', escape: false);
    }

    public function test_slug_change_redirects_old_url_to_new(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'slug' => 'old-slug', 'name' => 'Redirect Me']);

        $this->actingAs($user)->put(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'slug' => 'new-slug',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect();

        $this->assertDatabaseHas('event_slug_redirects', [
            'slug' => 'old-slug',
            'event_id' => $event->id,
        ]);
        $this->assertSame('new-slug', $event->fresh()->slug);

        $this->get(route('events.public', 'old-slug'))
            ->assertRedirect(route('events.public', 'new-slug'));

        $this->get(route('events.public', 'new-slug'))
            ->assertOk()
            ->assertSee('Redirect Me', escape: false);
    }

    public function test_reclaiming_old_slug_removes_redirect(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'slug' => 'alpha']);

        $this->actingAs($user)->put(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'slug' => 'beta',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect();

        $this->assertDatabaseHas('event_slug_redirects', ['slug' => 'alpha', 'event_id' => $event->id]);

        $this->actingAs($user)->put(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'slug' => 'alpha',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect();

        $this->assertDatabaseMissing('event_slug_redirects', ['slug' => 'alpha']);
        $this->assertSame('alpha', $event->fresh()->slug);
    }

    public function test_unpublished_and_unknown_slugs_are_404(): void
    {
        $draft = Event::factory()->create([
            'is_published' => false,
            'slug' => 'draft-only',
        ]);

        $this->get(route('events.public', $draft->slug))->assertNotFound();
        $this->get(route('events.public', 'no-such-slug'))->assertNotFound();
    }

    public function test_private_published_event_is_403(): void
    {
        $event = $this->liveEvent(['is_public' => false]);

        $this->get(route('events.public', $event->slug))->assertForbidden();
    }

    public function test_status_pages_do_not_increment_views(): void
    {
        $event = $this->liveEvent([
            'invitation_paused_at' => now(),
            'invitation_views_count' => 5,
        ]);

        $this->get(route('events.public', $event->slug))->assertOk();

        $this->assertSame(5, $event->fresh()->invitation_views_count);
    }

    public function test_slug_cannot_collide_with_another_events_redirect(): void
    {
        $owner = User::factory()->create();
        $first = $this->liveEvent(['user_id' => $owner->id, 'slug' => 'taken-once']);
        $second = $this->liveEvent(['user_id' => $owner->id, 'slug' => 'other-slug', 'name' => 'Second']);

        $this->actingAs($owner)->put(route('events.update', $first), [
            'name' => $first->name,
            'event_type' => $first->event_type,
            'event_date' => $first->event_date->format('Y-m-d'),
            'event_time' => substr((string) $first->event_time, 0, 5),
            'slug' => 'moved-away',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect();

        $this->assertTrue(EventSlugRedirect::query()->where('slug', 'taken-once')->exists());

        $this->actingAs($owner)->from(route('events.edit', $second))->put(route('events.update', $second), [
            'name' => $second->name,
            'event_type' => $second->event_type,
            'event_date' => $second->event_date->format('Y-m-d'),
            'event_time' => substr((string) $second->event_time, 0, 5),
            'slug' => 'taken-once',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect(route('events.edit', $second))
            ->assertSessionHasErrors('slug');
    }

    public function test_auto_generated_slug_does_not_hijack_another_events_redirect(): void
    {
        $owner = User::factory()->create();
        $original = $this->liveEvent(['user_id' => $owner->id, 'slug' => 'the-jones-wedding', 'name' => 'Old Name']);

        // Change the slug away, leaving "the-jones-wedding" as a live redirect.
        $this->actingAs($owner)->put(route('events.update', $original), [
            'name' => $original->name,
            'event_type' => $original->event_type,
            'event_date' => $original->event_date->format('Y-m-d'),
            'event_time' => substr((string) $original->event_time, 0, 5),
            'slug' => 'jones-wedding-2026',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect();

        $this->assertDatabaseHas('event_slug_redirects', ['slug' => 'the-jones-wedding', 'event_id' => $original->id]);

        // A completely unrelated event, created with no custom slug, whose name
        // happens to auto-slugify to the exact string that redirect owns.
        $otherOwner = User::factory()->create();
        $this->actingAs($otherOwner)->post(route('events.store'), [
            'name' => 'The Jones Wedding',
            'event_type' => 'wedding',
            'product_kind' => 'invitation',
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertRedirect();

        $intruder = Event::where('user_id', $otherOwner->id)->first();
        $this->assertNotNull($intruder);
        $this->assertNotSame('the-jones-wedding', $intruder->slug);

        // The old redirect still resolves to the original event, not the new one.
        $this->get(route('events.public', 'the-jones-wedding'))
            ->assertRedirect(route('events.public', 'jones-wedding-2026'));
    }

    public function test_token_rsvp_show_handles_soft_deleted_event(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'name' => 'Deleted Wedding']);
        $guest = Guest::factory()->for($event)->create();

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

        $this->get(route('rsvp.token.show', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertSee('Invitation no longer available', escape: false);
    }

    public function test_token_rsvp_store_refuses_soft_deleted_event_instead_of_crashing(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id]);
        $guest = Guest::factory()->for($event)->create();

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

        $this->post(route('rsvp.token.store', ['token' => $guest->invitation_token]), [
            'status' => 'accepted',
            'attendee_count' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('rsvps', ['guest_id' => $guest->id]);
    }

    public function test_entry_pass_qr_refuses_soft_deleted_event_instead_of_crashing(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id]);
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($guest)->create();

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

        $this->get(route('rsvp.token.entry-pass', ['token' => $guest->invitation_token]))
            ->assertNotFound();
    }

    public function test_token_rsvp_thanks_page_handles_soft_deleted_event(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id, 'name' => 'Deleted Bash']);
        $guest = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($guest)->create();

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

        $this->get(route('rsvp.token.thanks', ['token' => $guest->invitation_token]))
            ->assertOk()
            ->assertSee('Invitation no longer available', escape: false);
    }

    public function test_guest_checkin_scanner_link_handles_soft_deleted_event(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent(['user_id' => $user->id]);
        $link = EventStaffLink::factory()->for($event)->create();

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

        $this->get(route('checkin.public.scan', ['staffToken' => $link->token]))
            ->assertOk()
            ->assertSee("This scanner link isn't active", escape: false);

        $this->get(route('checkin.public.lookup', ['staffToken' => $link->token]).'?q=abc')
            ->assertNotFound();
    }

    public function test_ticket_checkin_scanner_link_handles_soft_deleted_event(): void
    {
        $user = User::factory()->create();
        $event = $this->liveEvent([
            'user_id' => $user->id,
            'product_kind' => EventProductKind::Ticketed,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
        $link = EventStaffLink::factory()->for($event)->create();

        $this->actingAs($user)->delete(route('events.destroy', $event))->assertRedirect();

        $this->get(route('tickets.checkin.public.lookup', ['staffToken' => $link->token]).'?q=abc')
            ->assertNotFound();
    }

    public function test_admin_cannot_delete_event_with_a_pending_ticket_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        $event = $this->liveEvent([
            'product_kind' => EventProductKind::Ticketed,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ]);
        TicketOrder::factory()->for($event)->create();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.events.show', $event))
            ->delete(route('admin.events.destroy', $event))
            ->assertRedirect(route('admin.events.show', $event))
            ->assertSessionHasErrors('event');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }
}
