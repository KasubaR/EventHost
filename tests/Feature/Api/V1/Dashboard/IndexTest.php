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

    public function test_audience_filter_scopes_analytics_to_that_portal(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->published()->privateAudience()->create();
        Event::factory()->for($user)->published()->publicAudience()->create();
        Event::factory()->for($user)->published()->publicAudience()->create();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/host/dashboard?audience=private')
            ->assertOk()
            ->assertJsonPath('analytics.totals.events', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/host/dashboard?audience=public')
            ->assertOk()
            ->assertJsonPath('analytics.totals.events', 2);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/host/dashboard')
            ->assertOk()
            ->assertJsonPath('analytics.totals.events', 3);
    }

    public function test_staffing_is_hidden_from_the_private_audience(): void
    {
        $user = User::factory()->create();
        $hostEvent = Event::factory()->publicAudience()->create();
        EventStaff::factory()->for($hostEvent)->create([
            'user_id' => $user->id,
            'accepted_at' => now(),
        ]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/host/dashboard?audience=private')
            ->assertOk()
            ->assertJsonCount(0, 'staffing');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/host/dashboard?audience=public')
            ->assertOk()
            ->assertJsonCount(1, 'staffing');
    }

    public function test_public_audience_includes_commerce_totals_and_private_does_not(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->published()->privateAudience()->create();
        Event::factory()->for($user)->published()->ticketed()->create();
        $token = $this->tokenFor($user);

        $public = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/host/dashboard?audience=public');

        $public->assertOk()
            ->assertJsonStructure(['public_totals' => [
                'events', 'ticketed_events', 'open_registration_events', 'pending_review',
                'tickets_sold', 'checked_in', 'gross_amount', 'host_amount',
            ]])
            ->assertJsonPath('public_totals.events', 1)
            ->assertJsonPath('public_totals.ticketed_events', 1);

        $this->assertArrayNotHasKey('upcoming', $public->json('public_totals'));

        foreach (['?audience=private', ''] as $query) {
            $this->assertArrayNotHasKey(
                'public_totals',
                $this->withHeader('Authorization', 'Bearer '.$token)
                    ->getJson('/api/v1/host/dashboard'.$query)
                    ->assertOk()
                    ->json(),
            );
        }
    }
}
