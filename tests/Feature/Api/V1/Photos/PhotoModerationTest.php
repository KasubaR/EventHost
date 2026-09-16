<?php

namespace Tests\Feature\Api\V1\Photos;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Slice E — /api/v1/host/events/{event}/photos. JSON sibling of
 * App\Http\Controllers\EventPhotoController, mirroring
 * tests/Feature/EventPhotoModerationTest.php's fixture shape.
 */
class PhotoModerationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_approve_and_hide_a_photo(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $photo = EventPhoto::factory()->for($event)->pending()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson(route('api.v1.host.events.photos.update', ['event' => $event, 'photo' => $photo]), ['status' => 'approved'])
            ->assertOk();

        $this->assertSame(PhotoStatus::Approved, $photo->fresh()->status);
    }

    public function test_owner_can_delete_a_photo_and_its_files_are_removed(): void
    {
        Storage::fake('public');

        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $photo = EventPhoto::factory()->for($event)->create([
            'path' => 'event-photos/foo.jpg',
            'thumbnail_path' => 'event-photos/foo-thumb.jpg',
        ]);
        Storage::disk('public')->put($photo->path, 'x');
        Storage::disk('public')->put($photo->thumbnail_path, 'x');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson(route('api.v1.host.events.photos.destroy', ['event' => $event, 'photo' => $photo]))
            ->assertOk();

        $this->assertNull(EventPhoto::query()->find($photo->id));
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_a_photo_from_a_different_event_404s(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $otherEvent = Event::factory()->for($owner)->create();
        $photo = EventPhoto::factory()->for($otherEvent)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson(route('api.v1.host.events.photos.update', ['event' => $event, 'photo' => $photo]), ['status' => 'approved'])
            ->assertNotFound();
    }

    public function test_a_non_owner_cannot_moderate_photos(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $photo = EventPhoto::factory()->for($event)->create();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson(route('api.v1.host.events.photos.index', $event))
            ->assertForbidden();
    }
}
