<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_restore_brings_back_a_soft_deleted_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $event->delete();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/restore")
            ->assertOk()
            ->assertJsonPath('deleted_at', null);

        $this->assertNull($event->fresh()->deleted_at);
    }

    public function test_restoring_a_non_trashed_event_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/host/events/{$event->id}/restore")
            ->assertOk();

        $this->assertNull($event->fresh()->deleted_at);
    }

    public function test_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $event->delete();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/host/events/{$event->id}/restore")
            ->assertForbidden();
    }
}
