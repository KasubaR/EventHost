<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_default_status_returns_published_events_only(): void
    {
        $user = User::factory()->create();
        $published = Event::factory()->for($user)->published()->create();
        Event::factory()->for($user)->create(); // draft, is_published=false

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/events');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $published->id)
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'is_check_in_open',
                        'check_in_closed_reason',
                        'can_check_in',
                    ],
                ],
            ]);

        $this->assertIsBool($response->json('data.0.is_check_in_open'));
    }

    public function test_status_draft_returns_only_unpublished_events(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->published()->create();
        $draft = Event::factory()->for($user)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/events?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $draft->id);
    }

    public function test_status_deleted_returns_only_trashed_events(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $event->delete();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/events?status=deleted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id);
    }

    public function test_events_are_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        Event::factory()->published()->create(); // someone else's

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/events')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_kind_filter_narrows_to_the_requested_product_kind(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->published()->create();
        $ticketed = Event::factory()->for($user)->published()->ticketed()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/events?kind=ticketed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ticketed->id);
    }
}
