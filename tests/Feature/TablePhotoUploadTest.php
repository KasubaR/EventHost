<?php

namespace Tests\Feature;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TablePhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick is required for photo processing.');
        }
    }

    public function test_guest_can_upload_a_photo_which_is_approved_by_default(): void
    {
        Storage::fake('public');

        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        $table = EventTable::factory()->for($event)->create();

        $response = $this->postJson(
            route('table.upload.store', ['slug' => $event->slug, 'code' => $table->code]),
            [
                'photo' => UploadedFile::fake()->image('photo.jpg', 1200, 900),
                'uploader_name' => 'Jane Guest',
            ]
        );

        $response->assertCreated();

        $photo = EventPhoto::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($photo);
        $this->assertSame(PhotoStatus::Approved, $photo->status);
        $this->assertSame('Jane Guest', $photo->uploader_name);
        $this->assertSame($table->id, $photo->event_table_id);
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('public')->assertExists($photo->thumbnail_path);

        $this->assertSame(1, $table->fresh()->photos_count);
    }

    public function test_upload_is_pending_when_event_requires_approval(): void
    {
        Storage::fake('public');

        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'is_public' => true,
            'is_published' => true,
            'photo_wall_requires_approval' => true,
        ]);
        $table = EventTable::factory()->for($event)->create();

        $this->postJson(
            route('table.upload.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->image('photo.jpg', 800, 600)]
        )->assertCreated();

        $photo = EventPhoto::query()->where('event_id', $event->id)->first();
        $this->assertSame(PhotoStatus::Pending, $photo->status);
    }

    public function test_upload_rejected_when_owner_is_not_premium(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        $table = EventTable::factory()->for($event)->create();

        $this->postJson(
            route('table.upload.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->image('photo.jpg')]
        )->assertForbidden();

        $this->assertSame(0, EventPhoto::query()->count());
    }

    public function test_upload_route_is_forbidden_for_a_non_public_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => false, 'is_published' => true]);
        $table = EventTable::factory()->for($event)->create();

        // Private is a 403 (invite-only, existence not hidden) — see
        // PublicInvitationResolver::resolveSibling().
        $this->postJson(
            route('table.upload.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->image('photo.jpg')]
        )->assertForbidden();
    }

    public function test_upload_requires_a_valid_image_file(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        $table = EventTable::factory()->for($event)->create();

        $this->postJson(
            route('table.upload.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]
        )->assertUnprocessable();
    }
}
