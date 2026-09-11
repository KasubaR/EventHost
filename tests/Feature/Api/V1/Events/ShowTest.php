<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_sees_the_full_event_with_summaries(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}");

        $response->assertOk()
            ->assertJsonPath('id', $event->id)
            ->assertJsonStructure(['rsvp_summary' => ['invited', 'pending', 'accepted', 'declined', 'maybe', 'accepted_heads'], 'analytics']);

        $this->assertArrayNotHasKey('contribution_summary', $response->json());
    }

    public function test_contribution_summary_only_appears_when_event_accepts_contributions(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}")
            ->assertOk()
            ->assertJsonStructure(['contribution_summary' => ['pledges', 'completed', 'collected']]);
    }

    public function test_accepted_manager_staff_can_view(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->ticketed()->create();
        EventStaff::factory()->for($event)->manager()->create([
            'user_id' => $staffer->id,
            'accepted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->getJson("/api/v1/host/events/{$event->id}")
            ->assertOk();
    }

    public function test_non_owner_non_staff_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/host/events/{$event->id}")
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $event = Event::factory()->published()->create();

        $this->getJson("/api/v1/host/events/{$event->id}")->assertUnauthorized();
    }
}
