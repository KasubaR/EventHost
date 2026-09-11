<?php

namespace Tests\Feature\Api\V1\Rsvp;

use App\Models\Event;
use App\Models\EventSlugRedirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenShowTest extends TestCase
{
    use RefreshDatabase;

    private function publishedEvent(array $overrides = []): Event
    {
        $user = User::factory()->create();

        return Event::factory()->for($user)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => null,
        ], $overrides));
    }

    public function test_public_open_event_returns_the_rsvp_form_config(): void
    {
        $event = $this->publishedEvent();

        $response = $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]));

        $response->assertOk()
            ->assertJsonPath('rsvp_open', true)
            ->assertJsonPath('status', null)
            ->assertJsonStructure(['event', 'rsvp_open', 'rsvp_form', 'status']);
    }

    public function test_private_event_is_forbidden(): void
    {
        $event = $this->publishedEvent(['is_public' => false]);

        $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]))
            ->assertForbidden();
    }

    public function test_draft_event_is_not_found(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['is_public' => true, 'is_published' => false]);

        $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]))
            ->assertNotFound();
    }

    public function test_cancelled_event_returns_a_status_body_not_a_404(): void
    {
        $event = $this->publishedEvent(['cancelled_at' => now()]);

        $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]))
            ->assertOk()
            ->assertJsonPath('status.status', 'cancelled')
            ->assertJsonPath('rsvp_open', false);
    }

    public function test_paused_event_returns_a_status_body(): void
    {
        $event = $this->publishedEvent(['invitation_paused_at' => now()]);

        $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]))
            ->assertOk()
            ->assertJsonPath('status.status', 'unavailable');
    }

    public function test_soft_deleted_event_returns_a_gone_status_body(): void
    {
        $event = $this->publishedEvent();
        $event->delete();

        $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]))
            ->assertOk()
            ->assertJsonPath('status.status', 'gone');
    }

    public function test_ended_event_returns_an_ended_status_body(): void
    {
        $event = $this->publishedEvent(['event_date' => now()->subMonth()->format('Y-m-d')]);

        $this->getJson(route('api.v1.rsvp.open.show', ['slug' => $event->slug]))
            ->assertOk()
            ->assertJsonPath('status.status', 'ended')
            ->assertJsonPath('rsvp_open', false);
    }

    public function test_renamed_slug_redirects(): void
    {
        $event = $this->publishedEvent();
        EventSlugRedirect::create(['slug' => 'old-slug', 'event_id' => $event->id, 'created_at' => now()]);

        $this->get(route('api.v1.rsvp.open.show', ['slug' => 'old-slug']))
            ->assertRedirect(route('events.public', ['slug' => $event->slug]));
    }
}
