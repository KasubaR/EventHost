<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
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

    /**
     * Phase 3 of plans/public-private-portals.md replaced the old ?kind=
     * invitation/ticketed tabs with the audience-scoped events.index vs
     * public-events.index split — a ticketed event (always public audience)
     * never appears on the private "My Events" list at all now, and vice
     * versa. See EventTypeTaxonomyTest / EventAudienceTest for the audience
     * mechanics themselves; this just checks the two index pages partition
     * a host's events correctly.
     */
    public function test_events_index_only_shows_private_events_public_events_index_only_shows_public(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->privateAudience()->create(['name' => 'Garden RSVP']);
        Event::factory()->for($user)->ticketed()->create(['name' => 'Concert Tickets']);
        Event::factory()->for($user)->publicAudience()->create(['name' => 'Open Fundraiser']);

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Garden RSVP')
            ->assertDontSee('Concert Tickets')
            ->assertDontSee('Open Fundraiser');

        $this->actingAs($user)
            ->get(route('public-events.index'))
            ->assertOk()
            ->assertSee('Concert Tickets')
            ->assertSee('Open Fundraiser')
            ->assertDontSee('Garden RSVP');
    }

    public function test_store_creates_draft_and_redirects_to_choose_template(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Gathering',
            'event_type' => 'birthday',
            'audience' => 'private',
            'product_kind' => 'invitation',
            'description' => null,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'location_name' => null,
            'latitude' => null,
            'longitude' => null,
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
            'audience' => 'private',
            'product_kind' => 'invitation',
            'description' => null,
            'event_date' => now()->addWeek()->format('Y-m-d'),
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'location_name' => null,
            'latitude' => null,
            'longitude' => null,
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

    public function test_cover_image_waits_until_a_layout_that_uses_one_is_chosen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('events.create', ['audience' => 'private']))
            ->assertOk()
            ->assertDontSee('Cover Image', false)
            ->assertDontSee('Upload cover', false);

        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $this->actingAs($user)->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Choose invitation layout', false)
            ->assertDontSee('Cover Image', false);

        $withoutCover = InvitationTemplate::query()->where('slug', 'modern-minimal')->firstOrFail();
        $event->update(['invitation_template_id' => $withoutCover->id]);

        $this->actingAs($user)->get(route('events.edit', $event))
            ->assertOk()
            ->assertDontSee('Upload cover', false);

        $withCover = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();
        $event->update(['invitation_template_id' => $withCover->id]);

        $this->actingAs($user)->get(route('events.edit', $event))
            ->assertOk()
            ->assertSee('Cover Image', false)
            ->assertSee('Upload cover', false);
    }

    public function test_botanical_edit_page_shows_dual_portraits_not_cover_or_hero_portrait(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'graduation-template-2-botanical-blush')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Hero portraits', false);
        $response->assertSee('Portrait photos (up to 2)', false);
        $response->assertSee('Up to five WebP images', false);
        $response->assertDontSee('Up to six WebP images', false);
        $response->assertSee('evt-design-media-file', false);
        $response->assertSee('data-upload-slot="couple"', false);
        $response->assertDontSee('Couple / dual portraits', false);
        $response->assertDontSee('Upload hero portrait', false);
        $response->assertDontSee('Cover Image', false);
        $response->assertDontSee('Upload cover', false);
    }

    public function test_event_invite_edit_page_shows_no_image_upload_controls(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'event-invite')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertDontSee('event cover photo', false);
        $response->assertDontSee('Cover Image', false);
        $response->assertDontSee('Upload cover', false);
        $response->assertDontSee('Upload hero portrait', false);
        $response->assertDontSee('Portrait photos (up to 2)', false);
        $response->assertDontSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('data-upload-slot="couple"', false);
        $response->assertDontSee('data-upload-slot="hero_portrait"', false);
        $response->assertDontSee('data-upload-slot="cover"', false);
        $response->assertDontSee('id="gallery_images"', false);
    }

    public function test_noir_wedding_edit_page_matches_cover_and_six_gallery_slots(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation-2')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Hero photo', false);
        $response->assertSee('Tall portrait on the left of the opening screen', false);
        $response->assertSee('Upload cover', false);
        $response->assertSee('Photo gallery', false);
        $response->assertSee('two rows of three', false);
        $response->assertSee('Up to six', false);
        $response->assertSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('Portrait photos (up to 2)', false);
        $response->assertDontSee('Couple portraits (3 slots', false);
        $response->assertDontSee('data-upload-slot="couple"', false);
        $response->assertDontSee('data-upload-slot="hero_portrait"', false);
        $response->assertDontSee('event cover photo', false);
    }

    public function test_classic_edit_page_matches_cover_only_no_gallery(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'slate-minimal')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Cover Image', false);
        $response->assertSee('Wide banner across the top of the invitation', false);
        $response->assertSee('1200×630', false);
        $response->assertSee('Upload cover', false);
        $response->assertDontSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('id="gallery_images"', false);
        $response->assertDontSee('Up to six WebP', false);
        $response->assertDontSee('Up to five WebP', false);
        $response->assertDontSee('Portrait photos (up to 2)', false);
        $response->assertDontSee('Couple portraits (3 slots', false);
        $response->assertDontSee('data-upload-slot="couple"', false);
        $response->assertDontSee('data-upload-slot="hero_portrait"', false);
        $response->assertDontSee('event cover photo', false);
    }

    public function test_modern_minimal_edit_page_matches_five_gallery_no_cover(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'modern-minimal')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Up to five photos in the photo grid', false);
        $response->assertSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('Cover Image', false);
        $response->assertDontSee('Upload cover', false);
        $response->assertDontSee('data-upload-slot="couple"', false);
        $response->assertDontSee('data-upload-slot="hero_portrait"', false);
        $response->assertDontSee('event cover photo', false);
    }

    public function test_pro_magazine_edit_page_matches_cover_and_gallery(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'pro-magazine')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Cover Image', false);
        $response->assertSee('Full-bleed magazine hero', false);
        $response->assertSee('Upload cover', false);
        $response->assertSee('Up to six WebP images', false);
        $response->assertSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('data-upload-slot="couple"', false);
        $response->assertDontSee('data-upload-slot="hero_portrait"', false);
        $response->assertDontSee('event cover photo', false);
    }

    public function test_ivory_gold_wedding_edit_page_matches_cover_couple_and_gallery(): void
    {
        $user = User::factory()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'wedding-invitation')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Cover Image', false);
        $response->assertSee('Cinematic hero and details backdrop', false);
        $response->assertSee('Upload cover', false);
        $response->assertSee('Couple portraits (3 slots', false);
        $response->assertSee('data-upload-slot="couple"', false);
        $response->assertSee('story panel can also use a gallery photo', false);
        $response->assertSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('data-upload-slot="hero_portrait"', false);
        $response->assertDontSee('event cover photo', false);
    }

    public function test_beauty_for_ashes_edit_page_matches_speakers_only_no_cover_or_gallery(): void
    {
        $user = User::factory()->pro()->create();
        $tpl = InvitationTemplate::query()->where('slug', 'beauty-for-ashes')->firstOrFail();
        $event = Event::factory()->for($user)->create([
            'invitation_template_id' => $tpl->id,
        ]);

        $response = $this->actingAs($user)->get(route('events.edit', $event));

        $response->assertOk();
        $response->assertSee('Speaker portrait slots', false);
        $response->assertSee('data-upload-slot="speaker:0"', false);
        $response->assertDontSee('Cover Image', false);
        $response->assertDontSee('Upload cover', false);
        $response->assertDontSee('data-upload-slot="gallery"', false);
        $response->assertDontSee('id="gallery_images"', false);
        $response->assertDontSee('event cover photo', false);
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

    /**
     * Was a publicAudience() event asserting /e/{slug} became visible after
     * publish. Since plans/public-private-portals.md Phase 4c, a public
     * audience invitation event is "free registration" and can no longer
     * credit-publish at all (admin approval + a paid quote is required
     * instead — see PublicRegistrationApprovalTest). Rewritten to a private
     * event: /e/{slug} now renders for a private event too (it's just never
     * listed anywhere — see PublicInvitationLifecycleTest), which is exactly
     * where EventController::publish() redirects the host after publishing.
     */
    public function test_publish_requires_owner_and_the_owner_can_then_view_it(): void
    {
        $user = User::factory()->create();
        $template = InvitationTemplate::query()->where('is_active', true)->firstOrFail();
        $event = Event::factory()->for($user)->privateAudience()->create([
            'is_published' => false,
            'invitation_template_id' => $template->id,
        ]);

        $intruder = User::factory()->create();
        $denied = $this->actingAs($intruder)->patch(route('events.publish', $event));
        $denied->assertForbidden();

        $response = $this->actingAs($user)->patch(route('events.publish', $event));

        $response->assertRedirect(route('events.public', $event->fresh()->slug));
        $response->assertSessionHas('status', 'published');
        $this->assertTrue((bool) $event->fresh()->is_published);

        // The redirect above lands the host straight on this page — it must
        // actually render, not 403, even though the event is private.
        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee($event->name, escape: false);

        $preview = $this->actingAs($user)->get(route('events.preview', $event));
        $preview->assertOk();
        $preview->assertSee($event->name, escape: false);
    }

    public function test_show_page_offers_a_copy_link_button_for_the_invitation_link(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->privateAudience()->published()->create();

        $response = $this->actingAs($user)->get(route('events.show', $event));

        $response->assertOk();
        $response->assertSee('data-copy-text="'.route('events.public', $event->slug).'"', escape: false);
        $response->assertDontSee('has no public page', escape: false);
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
            'audience' => 'private',
            'product_kind' => 'invitation',
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

    public function test_base_host_cannot_set_guest_limit_above_plan_capacity(): void
    {
        $user = User::factory()->create([
            'subscription_tier' => SubscriptionTier::Base,
        ]);

        $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'guest_limit' => 151,
        ]))->assertSessionHasErrors('guest_limit');

        $this->assertNull(Event::where('user_id', $user->id)->first());
    }

    public function test_pro_host_cannot_set_guest_limit_above_plan_capacity(): void
    {
        $user = User::factory()->pro()->create();

        $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'guest_limit' => 301,
        ]))->assertSessionHasErrors('guest_limit');

        $this->assertNull(Event::where('user_id', $user->id)->first());
    }

    public function test_pro_plus_host_can_set_guest_limit_above_pro_cap(): void
    {
        $user = User::factory()->proPlus()->create();

        $response = $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'guest_limit' => 500,
        ]));

        $response->assertSessionHasNoErrors();
        $event = Event::where('user_id', $user->id)->first();
        $this->assertNotNull($event);
        $this->assertSame(500, (int) $event->guest_limit);
    }

    public function test_open_to_all_guest_limit_still_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('events.store'), $this->baseStorePayload($user, [
            'guest_limit' => null,
        ]));

        $response->assertSessionHasNoErrors();
        $event = Event::where('user_id', $user->id)->first();
        $this->assertNotNull($event);
        $this->assertNull($event->guest_limit);
    }

    public function test_guest_settings_show_plan_capacity_and_upgrade_link(): void
    {
        $user = User::factory()->create([
            'subscription_tier' => SubscriptionTier::Base,
        ]);

        $this->actingAs($user)
            ->get(route('events.create', ['audience' => 'private', 'product_kind' => 'invitation']))
            ->assertOk()
            ->assertSee('Your plan allows up to', escape: false)
            ->assertSee('150', escape: false)
            ->assertSee('Upgrade to Pro', escape: false);
    }
}
