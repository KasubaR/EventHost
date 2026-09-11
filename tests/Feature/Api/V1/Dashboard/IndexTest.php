<?php

namespace Tests\Feature\Api\V1\Dashboard;

use App\Models\Event;
use App\Models\EventStaff;
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

    public function test_returns_analytics_and_staffing_without_pending_custom_quote(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->published()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/dashboard');

        $response->assertOk()
            ->assertJsonStructure(['analytics' => ['has_events', 'totals', 'daily_rsvps', 'status_chart', 'group_breakdown', 'top_guests'], 'staffing'])
            ->assertJsonPath('analytics.has_events', true)
            ->assertJsonPath('analytics.totals.events', 1);

        $this->assertArrayNotHasKey('pendingCustomQuote', $response->json());
        $this->assertArrayNotHasKey('pending_custom_quote', $response->json());
    }

    public function test_staffing_lists_events_with_an_accepted_staff_role_separately_from_owned(): void
    {
        $user = User::factory()->create();
        $hostEvent = Event::factory()->create(); // owned by someone else
        EventStaff::factory()->for($hostEvent)->create([
            'user_id' => $user->id,
            'accepted_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/host/dashboard');

        $response->assertOk()
            ->assertJsonPath('analytics.has_events', false) // owns nothing itself
            ->assertJsonCount(1, 'staffing')
            ->assertJsonPath('staffing.0.id', $hostEvent->id);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/host/dashboard')->assertUnauthorized();
    }
}
