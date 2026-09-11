<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_create_a_guest_with_group_and_mark_sent(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $group = GuestGroup::factory()->for($event)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests", [
                'name' => 'Taylor Example',
                'email' => 'taylor@example.test',
                'guest_group_id' => $group->id,
                'mark_invitation_sent' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Taylor Example')
            ->assertJsonPath('invitation_sent', true);

        $guest = Guest::where('email', 'taylor@example.test')->firstOrFail();
        $this->assertSame($group->id, $guest->guest_group_id);
        $this->assertNotNull($guest->invitation_token);
    }

    public function test_duplicate_email_within_event_is_rejected(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['email' => 'dup@example.test']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests", [
                'name' => 'Someone Else',
                'email' => 'dup@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_capacity_exceeded_returns_structured_error(): void
    {
        $owner = User::factory()->create(); // Base tier -> capacity 150
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->count(150)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests", ['name' => 'One Too Many']);

        $response->assertStatus(422)->assertJsonPath('error', 'guest_capacity_reached');
        $this->assertSame(150, Guest::where('event_id', $event->id)->count());
    }

    public function test_accepted_manager_staff_can_create_a_guest(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->postJson("/api/v1/host/events/{$event->id}/guests", ['name' => 'Manager Added'])
            ->assertCreated();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/host/events/{$event->id}/guests", ['name' => 'Sneaky'])
            ->assertForbidden();

        $this->assertSame(0, Guest::where('event_id', $event->id)->count());
    }
}
