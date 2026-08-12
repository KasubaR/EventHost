<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventGalleryTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_shows_only_approved_photos(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);

        $approved = EventPhoto::factory()->for($event)->create();
        EventPhoto::factory()->for($event)->pending()->create();
        EventPhoto::factory()->for($event)->hidden()->create();

        $response = $this->get(route('event.gallery.show', $event->slug));

        $response->assertOk();
        $response->assertSee('data-photo-id="'.$approved->id.'"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'data-photo-id='));
    }

    public function test_gallery_is_empty_when_owner_is_not_premium(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);
        EventPhoto::factory()->for($event)->create();

        $response = $this->get(route('event.gallery.show', $event->slug));

        $response->assertOk();
        $this->assertStringNotContainsString('data-photo-id=', $response->getContent());
    }

    public function test_feed_returns_only_photos_after_the_given_cursor(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(['is_public' => true, 'is_published' => true]);

        $first = EventPhoto::factory()->for($event)->create();
        $second = EventPhoto::factory()->for($event)->create();

        $response = $this->getJson(route('event.gallery.feed', $event->slug).'?after_id='.$first->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'photos');
        $response->assertJsonPath('photos.0.id', $second->id);
    }
}
