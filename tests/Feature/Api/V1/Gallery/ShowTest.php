<?php

namespace Tests\Feature\Api\V1\Gallery;

use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    private function liveGalleryEvent(array $overrides = []): Event
    {
        $owner = User::factory()->pro()->create();

        return Event::factory()->for($owner)->create(array_merge([
            'is_public' => true,
            'is_published' => true,
        ], $overrides));
    }

    public function test_only_approved_photos_are_shown(): void
    {
        $event = $this->liveGalleryEvent();

        $approved = EventPhoto::factory()->for($event)->create();
        EventPhoto::factory()->for($event)->pending()->create();
        EventPhoto::factory()->for($event)->hidden()->create();

        $response = $this->getJson(route('api.v1.gallery.show', ['slug' => $event->slug]));

        $response->assertOk()
            ->assertJsonPath('is_live', true)
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.id', $approved->id);
    }

    public function test_empty_when_owner_is_not_premium(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        EventPhoto::factory()->for($event)->create();

        $response = $this->getJson(route('api.v1.gallery.show', ['slug' => $event->slug]));

        $response->assertOk()
            ->assertJsonPath('is_live', false)
            ->assertJsonCount(0, 'photos');
    }
}
