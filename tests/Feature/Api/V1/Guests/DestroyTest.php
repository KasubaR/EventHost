<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestroyTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_delete_a_guest(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertNoContent();

        $this->assertNull(Guest::find($guest->id));
    }

    public function test_accepted_manager_staff_can_delete(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->deleteJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertNoContent();
    }

    public function test_stranger_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertForbidden();

        $this->assertNotNull(Guest::find($guest->id));
    }
}
