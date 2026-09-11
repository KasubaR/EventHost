<?php

namespace Tests\Feature\Api\V1\Contributions;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormShowTest extends TestCase
{
    use RefreshDatabase;

    private function contributingEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ], $overrides));
    }

    public function test_404s_when_admin_has_not_enabled_contributions(): void
    {
        $event = Event::factory()->create(['is_published' => true, 'is_public' => true]);

        $this->getJson(route('api.v1.contribute.show', ['slug' => $event->slug]))
            ->assertNotFound();
    }

    public function test_returns_event_and_bank_transfer_flag(): void
    {
        $event = $this->contributingEvent();

        $response = $this->getJson(route('api.v1.contribute.show', ['slug' => $event->slug]));

        $response->assertOk()
            ->assertJsonPath('event.slug', $event->slug)
            ->assertJsonStructure(['event', 'bank_transfer_enabled']);
    }

    public function test_private_event_is_forbidden(): void
    {
        $event = $this->contributingEvent(['is_public' => false]);

        $this->getJson(route('api.v1.contribute.show', ['slug' => $event->slug]))
            ->assertForbidden();
    }
}
