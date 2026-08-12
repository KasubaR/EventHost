<?php

namespace Tests\Feature;

use App\Enums\PhotoStatus;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventPhotoModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_approve_and_hide_a_photo(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $photo = EventPhoto::factory()->for($event)->pending()->create();

        $this->actingAs($owner)
            ->patch(route('events.photos.update', ['event' => $event, 'photo' => $photo]), ['status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(PhotoStatus::Approved, $photo->fresh()->status);

        $this->actingAs($owner)
            ->patch(route('events.photos.update', ['event' => $event, 'photo' => $photo]), ['status' => 'hidden'])
            ->assertRedirect();

        $this->assertSame(PhotoStatus::Hidden, $photo->fresh()->status);
    }

    public function test_owner_can_delete_a_photo_and_its_files_and_table_count_decrements(): void
    {
        Storage::fake('public');

        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['photos_count' => 1]);

        Storage::disk('public')->put('event-photos/a.webp', 'x');
        Storage::disk('public')->put('event-photos/thumbs/a.webp', 'x');

        $photo = EventPhoto::factory()->for($event)->for($table, 'table')->create([
            'path' => 'event-photos/a.webp',
            'thumbnail_path' => 'event-photos/thumbs/a.webp',
        ]);

        $this->actingAs($owner)
            ->delete(route('events.photos.destroy', ['event' => $event, 'photo' => $photo]))
            ->assertRedirect();

        $this->assertNull(EventPhoto::find($photo->id));
        Storage::disk('public')->assertMissing('event-photos/a.webp');
        Storage::disk('public')->assertMissing('event-photos/thumbs/a.webp');
        $this->assertSame(0, $table->fresh()->photos_count);
    }

    public function test_non_owner_cannot_moderate_photos(): void
    {
        $owner = User::factory()->pro()->create();
        $stranger = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $photo = EventPhoto::factory()->for($event)->create();

        $this->actingAs($stranger)
            ->patch(route('events.photos.update', ['event' => $event, 'photo' => $photo]), ['status' => 'hidden'])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get(route('events.photos.index', $event))
            ->assertForbidden();
    }
}
