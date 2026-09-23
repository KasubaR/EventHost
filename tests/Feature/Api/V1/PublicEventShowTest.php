<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\EventSlugRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicEventShowTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPublicEvent(array $overrides = []): Event
    {
        return Event::factory()->published()->create(array_merge([
            'is_public' => true,
        ], $overrides));
    }

    public function test_it_returns_full_invitation_json_for_a_live_event(): void
    {
        $event = $this->publishedPublicEvent(['description' => 'Join us for cake.']);

        $response = $this->getJson("/api/v1/events/{$event->slug}");

        $response->assertOk()
            ->assertJsonPath('slug', $event->slug)
            ->assertJsonPath('name', $event->name)
            ->assertJsonPath('description', 'Join us for cake.')
            ->assertJsonStructure([
                'slug', 'name', 'description', 'event_date', 'event_time', 'venue',
                'location_name', 'latitude', 'longitude', 'cover_image_url',
                'accepts_contributions', 'contribution_amount', 'branding_removed',
                'rsvp_open', 'rsvp_public_available',
                'invitation' => ['skin', 'layout_variant', 'theme', 'sections', 'content', 'media', 'effects', 'rsvp_form'],
            ]);

        $this->assertArrayNotHasKey('schema_version', $response->json('invitation'));
    }

    public function test_media_paths_are_resolved_to_full_urls(): void
    {
        $event = $this->publishedPublicEvent([
            'invitation_customization' => [
                'media' => ['gallery' => ['invitation-gallery/photo1.webp'], 'hero_portrait' => null, 'couple_photos' => []],
            ],
        ]);

        $response = $this->getJson("/api/v1/events/{$event->slug}");

        $response->assertOk();
        $gallery = $response->json('invitation.media.gallery');
        $this->assertNotEmpty($gallery);
        $this->assertStringStartsWith('http', $gallery[0]);
        $this->assertStringContainsString('storage/invitation-gallery/photo1.webp', $gallery[0]);
    }

    public function test_a_private_published_invitation_event_still_renders(): void
    {
        // Kept in step with the web PublicEventController: a private event's slug
        // is unlisted, not unreachable — see PublicInvitationLifecycleTest.
        $event = $this->publishedPublicEvent(['is_public' => false]);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('slug', $event->slug);
    }

    public function test_an_unknown_slug_is_not_found(): void
    {
        $this->getJson('/api/v1/events/does-not-exist')->assertNotFound();
    }

    public function test_a_renamed_slug_redirects(): void
    {
        $event = $this->publishedPublicEvent();
        EventSlugRedirect::create(['slug' => 'old-slug-name', 'event_id' => $event->id, 'created_at' => now()]);

        $this->getJson('/api/v1/events/old-slug-name')->assertRedirect(
            route('events.public', ['slug' => $event->slug])
        );
    }

    public function test_a_soft_deleted_event_returns_a_gone_status_body(): void
    {
        $event = $this->publishedPublicEvent();
        $event->delete();

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('status', 'gone');
    }

    public function test_a_cancelled_event_returns_a_cancelled_status_body(): void
    {
        $event = $this->publishedPublicEvent(['cancelled_at' => now()]);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
    }

    public function test_a_paused_event_returns_an_unavailable_status_body(): void
    {
        $event = $this->publishedPublicEvent(['invitation_paused_at' => now()]);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('status', 'unavailable');
    }

    public function test_a_past_published_event_returns_an_ended_status_body(): void
    {
        $event = $this->publishedPublicEvent(['event_date' => now()->subMonth()->format('Y-m-d')]);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('status', 'ended');
    }

    public function test_a_ticketed_event_returns_a_minimal_pointer(): void
    {
        $event = Event::factory()->published()->ticketed()->create();

        $response = $this->getJson("/api/v1/events/{$event->slug}");

        $response->assertOk()
            ->assertJsonPath('product_kind', 'ticketed')
            ->assertJsonPath('slug', $event->slug)
            ->assertJsonPath('public_url', route('events.public', ['slug' => $event->slug]));
    }

    public function test_viewing_a_live_event_increments_the_view_counter_but_a_soft_status_does_not(): void
    {
        $event = $this->publishedPublicEvent();
        $cancelled = $this->publishedPublicEvent(['cancelled_at' => now()]);

        $this->getJson("/api/v1/events/{$event->slug}");
        $this->getJson("/api/v1/events/{$cancelled->slug}");

        $this->assertSame(1, $event->fresh()->invitation_views_count);
        $this->assertSame(0, $cancelled->fresh()->invitation_views_count);
    }
}
