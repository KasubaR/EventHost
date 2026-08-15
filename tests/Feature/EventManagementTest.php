<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Models\User;
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

    public function test_store_creates_draft_and_redirects_to_choose_template(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('events.store'), [
            'name' => 'Summer Gathering',
            'event_type' => 'birthday',
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
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_cover_upload_removes_file_from_storage_when_deleted(): void
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

        Storage::disk('public')->assertMissing($event->cover_image);
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }
}
