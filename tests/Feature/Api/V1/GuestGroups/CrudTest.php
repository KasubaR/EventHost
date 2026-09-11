<?php

namespace Tests\Feature\Api\V1\GuestGroups;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\GuestGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_create_rename_and_delete_a_group(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $token = 'Bearer '.$this->tokenFor($owner);

        $created = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/host/events/{$event->id}/guest-groups", ['name' => 'VIP'])
            ->assertCreated()->json();

        $this->withHeader('Authorization', $token)
            ->patchJson("/api/v1/host/events/{$event->id}/guest-groups/{$created['id']}", ['name' => 'VIP Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'VIP Renamed');

        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/v1/host/events/{$event->id}/guest-groups/{$created['id']}")
            ->assertNoContent();

        $this->assertNull(GuestGroup::find($created['id']));
    }

    public function test_duplicate_name_within_event_is_rejected(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        GuestGroup::factory()->for($event)->create(['name' => 'Family']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guest-groups", ['name' => 'Family'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_accepted_manager_staff_can_manage_groups(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->postJson("/api/v1/host/events/{$event->id}/guest-groups", ['name' => 'Manager Group'])
            ->assertCreated();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/host/events/{$event->id}/guest-groups", ['name' => 'Sneaky'])
            ->assertForbidden();
    }
}
