<?php

namespace Tests\Feature\Api\V1\TableUpload;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick is required for photo processing.');
        }
    }

    private function liveGalleryEvent(array $overrides = []): Event
    {
        $owner = User::factory()->pro()->create();

        return Event::factory()->for($owner)->create(array_merge([
            'is_public' => true,
            'is_published' => true,
        ], $overrides));
    }

    public function test_upload_is_approved_by_default_and_returns_201(): void
    {
        Storage::fake('public');

        $event = $this->liveGalleryEvent();
        $table = EventTable::factory()->for($event)->create();

        $response = $this->postJson(
            route('api.v1.table.photos.store', ['slug' => $event->slug, 'code' => $table->code]),
            [
                'photo' => UploadedFile::fake()->image('photo.jpg', 1200, 900),
                'uploader_name' => 'Jane Guest',
            ]
        );

        // Decision #5 in the Slice B3 plan: this write endpoint keeps 201, unlike every other
        // B1/B2/B3 write endpoint — it's a genuinely created resource, not an upsert.
        $response->assertCreated()
            ->assertJsonPath('photo.status', 'approved');

        $photo = EventPhoto::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($photo);
        $this->assertSame($table->id, $photo->event_table_id);
        $this->assertSame(1, $table->fresh()->photos_count);
    }

    public function test_upload_is_pending_when_event_requires_approval(): void
    {
        Storage::fake('public');

        $event = $this->liveGalleryEvent(['photo_wall_requires_approval' => true]);
        $table = EventTable::factory()->for($event)->create();

        $this->postJson(
            route('api.v1.table.photos.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->image('photo.jpg', 800, 600)]
        )->assertCreated()->assertJsonPath('photo.status', 'pending');

        $photo = EventPhoto::query()->where('event_id', $event->id)->first();
        $this->assertSame(PhotoStatus::Pending, $photo->status);
    }

    public function test_non_premium_owner_is_forbidden(): void
    {
        Storage::fake('public');

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        $table = EventTable::factory()->for($event)->create();

        $this->postJson(
            route('api.v1.table.photos.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->image('photo.jpg')]
        )->assertForbidden();
    }

    public function test_non_image_file_is_rejected(): void
    {
        Storage::fake('public');

        $event = $this->liveGalleryEvent();
        $table = EventTable::factory()->for($event)->create();

        $this->postJson(
            route('api.v1.table.photos.store', ['slug' => $event->slug, 'code' => $table->code]),
            ['photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]
        )->assertUnprocessable()->assertJsonValidationErrors('photo');
    }
}
