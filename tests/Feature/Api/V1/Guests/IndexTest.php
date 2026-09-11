<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\Rsvp;
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

    public function test_returns_guests_stats_groups_and_tables(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests");

        $response->assertOk()
            ->assertJsonStructure([
                'guests' => ['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']],
                'stats' => ['total', 'pending', 'accepted', 'declined'],
                'groups', 'tables',
            ])
            ->assertJsonPath('stats.total', 1);
    }

    public function test_q_filters_by_name(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice Wonder']);
        Guest::factory()->for($event)->create(['name' => 'Bob Builder']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests?q=Alice");

        $response->assertOk()->assertJsonCount(1, 'guests.data');
    }

    public function test_response_filter_narrows_by_rsvp_status(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $accepted = Guest::factory()->for($event)->create();
        Rsvp::factory()->forGuest($accepted)->accepted(1)->create();
        Guest::factory()->for($event)->create(); // pending

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests?response=".RsvpStatus::Accepted->value);

        $response->assertOk()->assertJsonCount(1, 'guests.data');
    }

    public function test_accepted_manager_staff_can_view(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->getJson("/api/v1/host/events/{$event->id}/guests")
            ->assertOk();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/host/events/{$event->id}/guests")
            ->assertForbidden();
    }
}
