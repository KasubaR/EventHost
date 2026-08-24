<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\Rsvp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_events_index(): void
    {
        $response = $this->get(route('events.index'));

        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_events_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('events.index'));

        $response->assertOk();
    }

    public function test_events_index_splits_published_and_draft_events(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->create(['name' => 'Live Gala', 'is_published' => true]);
        Event::factory()->for($user)->create(['name' => 'Sketch Party', 'is_published' => false]);

        $response = $this->actingAs($user)->get(route('events.index'));

        $response->assertOk()
            ->assertSeeInOrder(['Published', 'Live Gala', 'Drafts', 'Sketch Party'], false);
    }

    public function test_events_index_has_separate_kind_tabs_and_filters_the_list(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->create(['name' => 'Garden RSVP']);
        Event::factory()->for($user)->ticketed()->create(['name' => 'Concert Tickets']);

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Garden RSVP')
            ->assertSee('Concert Tickets')
            ->assertSee(route('events.index', ['kind' => 'invitation']), escape: false)
            ->assertSee(route('events.index', ['kind' => 'ticketed']), escape: false);

        $this->actingAs($user)
            ->get(route('events.index', ['kind' => 'invitation']))
            ->assertOk()
            ->assertSee('Garden RSVP')
            ->assertDontSee('Concert Tickets');

        $this->actingAs($user)
            ->get(route('events.index', ['kind' => 'ticketed']))
            ->assertOk()
            ->assertSee('Concert Tickets')
            ->assertDontSee('Garden RSVP');
    }

    public function test_store_creates_draft_and_redirects_to_choose_template(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Gathering',
            'event_type' => 'birthday',
            'product_kind' => 'invitation',
            'description' => null,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'location_name' => null,
            'latitude' => null,
            'longitude' => null,
            'is_public' => '1',
            'rsvp_deadline' => null,
            'guest_limit' => null,
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ]);

        $event = Event::where('user_id', $user->id)->first();

        $this->assertNotNull($event);
        $this->assertFalse((bool) $event->is_published);
        $this->assertSame('Summer Gathering', $event->name);

        $response->assertRedirect(route('events.choose-template', $event));
        $response->assertSessionHas('status', 'draft-saved');
    }

    private function baseStorePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Summer Gathering',
            'event_type' => 'birthday',
            'product_kind' => 'invitation',
            'description' => null,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'location_name' => null,
            'latitude' => null,
            'longitude' => null,
            'is_public' => '1',
            'rsvp_deadline' => null,
            'guest_limit' => null,
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ], $overrides);
    }

    public function test_description_over_max_length_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'description' => str_repeat('a', 20001),
        ]))->assertSessionHasErrors('description');

        $this->assertNull(Event::where('user_id', $user->id)->first());
    }

    public function test_rsvp_deadline_on_the_same_day_as_the_event_is_accepted(): void
    {
        $user = User::factory()->create();
        $eventDate = now()->addWeek();

        $response = $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'event_date' => $eventDate->format('Y-m-d'),
            'event_time' => '19:00',
            // Same calendar day as the event, hours before it starts — this
            // is exactly the case 'before_or_equal:event_date' used to reject,
            // since it compared against event_date parsed as midnight.
            'rsvp_deadline' => $eventDate->format('Y-m-d').'T14:00',
        ]));

        $response->assertSessionHasNoErrors();
        $this->assertNotNull(Event::where('user_id', $user->id)->first());
    }

    public function test_rsvp_deadline_after_event_start_is_rejected(): void
    {
        $user = User::factory()->create();
        $eventDate = now()->addWeek();

        $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'event_date' => $eventDate->format('Y-m-d'),
            'event_time' => '14:00',
            'rsvp_deadline' => $eventDate->format('Y-m-d').'T19:00',
        ]))->assertSessionHasErrors('rsvp_deadline');

        $this->assertNull(Event::where('user_id', $user->id)->first());
    }

    public function test_event_time_already_passed_today_is_rejected(): void
    {
        // Frozen instead of now()->subHour(): near real midnight, subtracting
        // an hour would wrap to 23:xx the *previous* calendar day, which is
        // actually later than "today" at 00:xx — flaky rather than testing
        // anything. A fixed instant sidesteps that entirely.
        $this->travelTo(Carbon::create(2027, 6, 15, 14, 0, 0));

        $user = User::factory()->create();

        $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'event_date' => '2027-06-15',
            'event_time' => '10:00',
        ]))->assertSessionHasErrors('event_time');

        $this->assertNull(Event::where('user_id', $user->id)->first());
    }

    public function test_owner_can_view_choose_template_screen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $this->actingAs($user)->get(route('events.choose-template', $event))
            ->assertOk()
            ->assertSee('Choose an invitation layout', escape: false);
    }

    public function test_non_owner_cannot_patch_choose_template(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['invitation_template_id' => null]);
        $tpl = InvitationTemplate::query()->where('is_active', true)->firstOrFail();

        $this->actingAs($intruder)->patch(route('events.choose-template.update', $event), [
            'invitation_template_id' => (string) $tpl->id,
        ])->assertForbidden();
    }

    public function test_choose_template_assigns_template_and_redirects_to_edit(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);
        $tpl = InvitationTemplate::query()->where('is_active', true)->firstOrFail();

        $response = $this->actingAs($user)->patch(route('events.choose-template.update', $event), [
            'invitation_template_id' => (string) $tpl->id,
        ]);

        $response->assertRedirect(route('events.edit', $event));
        $response->assertSessionHas('status', 'template-chosen');
        $this->assertSame($tpl->id, $event->fresh()->invitation_template_id);
    }

    public function test_non_owner_cannot_update_another_users_event(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $response = $this->actingAs($intruder)->patch(route('events.update', $event), [
            'name' => 'Hijacked',
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ]);

        $response->assertForbidden();
        $this->assertSame($owner->id, $event->refresh()->user_id);
        $this->assertNotSame('Hijacked', $event->name);
    }

    public function test_upcoming_event_date_cannot_be_moved_into_the_past(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '18:00:00',
        ]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => now()->subWeek()->format('Y-m-d'),
            'event_time' => '18:00',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasErrors('event_date');

        $this->assertTrue($event->fresh()->event_date->isFuture());
    }

    public function test_already_past_event_date_can_still_be_corrected(): void
    {
        // Event::isLocked() (already past before this edit) is the carve-out —
        // the redefine-credit flow depends on being able to fix a historical
        // event's date, so the past-date guard must not block this case.
        $user = User::factory()->withCredits(5)->create();
        $event = Event::factory()->for($user)->published()->create([
            'event_date' => now()->subMonth()->format('Y-m-d'),
            'event_time' => '18:00:00',
        ]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => now()->subDays(20)->format('Y-m-d'),
            'event_time' => '18:00',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame(now()->subDays(20)->format('Y-m-d'), $event->fresh()->event_date->format('Y-m-d'));
    }

    public function test_rsvp_deadline_on_update_is_revalidated_against_a_changed_event_time(): void
    {
        $user = User::factory()->create();
        $eventDate = now()->addWeek()->format('Y-m-d');
        $event = Event::factory()->for($user)->published()->create([
            'event_date' => $eventDate,
            'event_time' => '19:00:00',
            'rsvp_deadline' => $eventDate.' 14:00:00',
        ]);

        // Moving the event earlier in the day pushes it before the
        // already-stored deadline — that conflict must be caught even
        // though rsvp_deadline itself is not part of this payload's intent
        // to change (the form always resubmits it, so it is present).
        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $eventDate,
            'event_time' => '10:00',
            'rsvp_deadline' => $eventDate.'T14:00',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasErrors('rsvp_deadline');
    }

    /**
     * Mirrors what the real edit form always submits — every field together,
     * every time (form-fields.blade.php renders them unconditionally, not
     * behind any "only if changed" logic). Keeping this realistic matters
     * for the tests further up that aren't about location fields at all —
     * see test_partial_update_does_not_wipe_stored_location_fields below for
     * the coverage on what happens when a caller genuinely omits them.
     */
    private function baseUpdatePayload(Event $event, array $overrides = []): array
    {
        return array_merge([
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'venue' => $event->venue,
            'location_name' => $event->location_name,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ], $overrides);
    }

    public function test_partial_update_does_not_wipe_stored_location_fields(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'venue' => 'Old Venue',
            'location_name' => 'Downtown',
            'latitude' => 1.234567,
            'longitude' => 2.345678,
        ]);

        // A genuinely partial payload — omits venue/location_name/latitude/
        // longitude entirely, unlike the real edit form which always
        // resubmits everything. prepareForValidation() used to coerce those
        // absent keys to null anyway, so fill() wiped them.
        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => 'Renamed Only',
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasNoErrors();

        $fresh = $event->fresh();
        $this->assertSame('Renamed Only', $fresh->name);
        $this->assertSame('Old Venue', $fresh->venue);
        $this->assertSame('Downtown', $fresh->location_name);
        $this->assertEqualsWithDelta(1.234567, $fresh->latitude, 0.00001);
        $this->assertEqualsWithDelta(2.345678, $fresh->longitude, 0.00001);
    }

    public function test_explicitly_clearing_location_fields_still_works(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'venue' => 'Old Venue',
            'location_name' => 'Downtown',
            'latitude' => 1.234567,
            'longitude' => 2.345678,
        ]);

        // The opposite of the previous test: these fields ARE present, just
        // empty — a cleared text input on the real form. That must still
        // null them out, not be mistaken for "not submitted".
        $this->actingAs($user)->patch(route('events.update', $event), $this->baseUpdatePayload($event, [
            'venue' => '',
            'location_name' => '',
            'latitude' => '',
            'longitude' => '',
        ]))->assertSessionHasNoErrors();

        $fresh = $event->fresh();
        $this->assertNull($fresh->venue);
        $this->assertNull($fresh->location_name);
        $this->assertNull($fresh->latitude);
        $this->assertNull($fresh->longitude);
    }

    public function test_partial_update_omitting_rsvp_deadline_still_checks_it_against_a_new_time(): void
    {
        $user = User::factory()->create();
        $eventDate = now()->addWeek()->format('Y-m-d');
        $event = Event::factory()->for($user)->create([
            'event_date' => $eventDate,
            'event_time' => '19:00:00',
            'rsvp_deadline' => $eventDate.' 14:00:00',
        ]);

        // rsvp_deadline is omitted entirely — the guard must fall back to
        // the stored value and still catch that moving the event earlier
        // strands it after the new start time.
        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $eventDate,
            'event_time' => '10:00',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasErrors('rsvp_deadline');

        $this->assertSame($eventDate.' 14:00:00', $event->fresh()->rsvp_deadline->format('Y-m-d H:i:s'));
    }

    public function test_partial_update_omitting_rsvp_deadline_preserves_it_when_still_valid(): void
    {
        $user = User::factory()->create();
        $eventDate = now()->addWeek()->format('Y-m-d');
        $event = Event::factory()->for($user)->create([
            'event_date' => $eventDate,
            'event_time' => '19:00:00',
            'rsvp_deadline' => $eventDate.' 14:00:00',
        ]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => 'New Name',
            'event_type' => $event->event_type,
            'event_date' => $eventDate,
            'event_time' => '19:00',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasNoErrors();

        $fresh = $event->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertSame($eventDate.' 14:00:00', $fresh->rsvp_deadline->format('Y-m-d H:i:s'));
    }

    public function test_longitude_without_latitude_is_still_rejected_on_a_partial_update(): void
    {
        // Regression guard for the prepareForValidation() fix above: latitude
        // is genuinely absent here (not merged in as null), so this confirms
        // required_with:longitude still fires for it rather than being
        // silently skipped because the field was not submitted.
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['latitude' => null, 'longitude' => null]);

        $this->actingAs($user)->patch(route('events.update', $event), [
            'name' => $event->name,
            'event_type' => $event->event_type,
            'event_date' => $event->event_date->format('Y-m-d'),
            'event_time' => substr((string) $event->event_time, 0, 5),
            'longitude' => '28.2871',
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ])->assertSessionHasErrors('latitude');

        $this->assertNull($event->fresh()->longitude);
    }

    public function test_venue_change_on_published_event_with_invited_guest_prompts_to_notify(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['venue' => 'Old Hall']);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);

        // The edit page's JS saves over fetch() with Accept: application/json —
        // the prompt has to come back as a JSON body rather than a session
        // flash, since a plain redirect would be auto-followed by fetch()
        // and the flash consumed before the page ever reloads to show it.
        $response = $this->actingAs($user)->patchJson(route('events.update', $event), $this->baseUpdatePayload($event, [
            'venue' => 'New Hall',
        ]));

        $response->assertOk();
        $response->assertJsonPath('notify_guests.count', 1);
        $response->assertJsonPath('notify_guests.url', route('events.guests.index', $event));
        $this->assertSame('New Hall', $event->fresh()->venue);
    }

    public function test_venue_change_prompts_for_an_rsvpd_guest_even_without_invitation_sent(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['venue' => 'Old Hall']);
        $guest = Guest::factory()->for($event)->create(['invitation_sent' => false]);
        Rsvp::factory()->forGuest($guest)->accepted()->create();

        $this->actingAs($user)->patchJson(route('events.update', $event), $this->baseUpdatePayload($event, [
            'venue' => 'New Hall',
        ]))->assertJsonPath('notify_guests.count', 1);
    }

    public function test_venue_change_on_published_event_with_no_guests_does_not_prompt(): void
    {
        // No count worth prompting over — falls through to the ordinary
        // redirect response, same as any save that has nothing to report.
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['venue' => 'Old Hall']);

        $this->actingAs($user)->patchJson(route('events.update', $event), $this->baseUpdatePayload($event, [
            'venue' => 'New Hall',
        ]))->assertRedirect();

        $this->assertSame('New Hall', $event->fresh()->venue);
    }

    public function test_venue_change_on_a_draft_event_does_not_prompt(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_published' => false, 'venue' => 'Old Hall']);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);

        $this->actingAs($user)->patchJson(route('events.update', $event), $this->baseUpdatePayload($event, [
            'venue' => 'New Hall',
        ]))->assertRedirect();

        $this->assertSame('New Hall', $event->fresh()->venue);
    }

    public function test_changing_an_unrelated_field_does_not_prompt(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['venue' => 'Old Hall']);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);

        $this->actingAs($user)->patchJson(route('events.update', $event), $this->baseUpdatePayload($event, [
            'name' => 'A new name for the same event',
        ]))->assertRedirect();

        $this->assertSame('A new name for the same event', $event->fresh()->name);
    }

    public function test_venue_change_flashes_notify_count_for_the_non_js_fallback(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['venue' => 'Old Hall']);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);

        // A plain browser form post (no Accept: application/json) — the
        // fallback path when event-edit-save.js has not run. This one goes
        // through the ordinary session flash + single redirect, since there
        // is no intermediate fetch hop to consume it.
        $this->actingAs($user)->patch(route('events.update', $event), $this->baseUpdatePayload($event, [
            'venue' => 'New Hall',
        ]))->assertSessionHas('notify_guests_count', 2);

        $this->actingAs($user)->get(route('events.edit', $event))
            ->assertSee('2 guests')
            ->assertSee('already have an invitation or RSVP for this event')
            ->assertSee('notify them from the guest list');
    }

    public function test_publish_requires_owner_and_shows_public_page(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_published' => false]);

        $intruder = User::factory()->create();
        $denied = $this->actingAs($intruder)->patch(route('events.publish', $event));
        $denied->assertForbidden();

        $response = $this->actingAs($user)->patch(route('events.publish', $event));

        $response->assertRedirect(route('events.public', $event->fresh()->slug));
        $response->assertSessionHas('status', 'published');
        $this->assertTrue((bool) $event->fresh()->is_published);

        $public = $this->get(route('events.public', $event->slug));
        $public->assertOk();
        $public->assertSee($event->name, escape: false);
    }

    public function test_unpublished_event_returns_404_on_public_route(): void
    {
        $event = Event::factory()->create(['is_published' => false]);

        $response = $this->get(route('events.public', $event->slug));

        $response->assertNotFound();
    }

    public function test_owner_can_delete_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete(route('events.destroy', $event));

        $response->assertRedirect(route('events.index'));
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }

    public function test_cover_file_is_kept_when_event_is_soft_deleted(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick is required for cover image processing.');
        }

        Storage::fake('public');

        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('cover.jpg', 1400, 900);

        $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Photo Party',
            'event_type' => 'corporate',
            'description' => null,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '18:00',
            'venue' => null,
            'location_name' => null,
            'latitude' => null,
            'longitude' => null,
            'cover_image' => $file,
            'product_kind' => 'invitation',
            'is_public' => '1',
            'rsvp_deadline' => null,
            'guest_limit' => null,
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ]);

        $event = Event::where('user_id', $user->id)->first();
        $this->assertNotNull($event->cover_image);
        Storage::disk('public')->assertExists($event->cover_image);

        $this->actingAs($user)->delete(route('events.destroy', $event));

        // Soft-delete keeps media so restore can bring the event back.
        Storage::disk('public')->assertExists($event->cover_image);
        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }
}
