<?php

namespace Tests\Feature\Api\V1\Gallery;

use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
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

    public function test_returns_only_photos_after_the_cursor(): void
    {
        $event = $this->liveGalleryEvent();

        $first = EventPhoto::factory()->for($event)->create();
        $second = EventPhoto::factory()->for($event)->create();

        $response = $this->getJson(route('api.v1.gallery.feed', ['slug' => $event->slug]).'?after_id='.$first->id);

        $response->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.id', $second->id);
    }

    public function test_empty_array_when_not_live(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        EventPhoto::factory()->for($event)->create();

        $this->getJson(route('api.v1.gallery.feed', ['slug' => $event->slug]))
            ->assertOk()
            ->assertJsonCount(0, 'photos');
    }
}
