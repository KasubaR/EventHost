<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_update_mark_sent_and_regenerate_token(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['name' => 'Old Name']);
        $oldToken = $guest->invitation_token;

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}", [
                'name' => 'New Name',
                'mark_invitation_sent' => true,
                'regenerate_invitation_token' => true,
            ]);

        $response->assertOk()->assertJsonPath('name', 'New Name')->assertJsonPath('invitation_sent', true);

        $guest->refresh();
        $this->assertNotSame($oldToken, $guest->invitation_token);
    }

    public function test_wrong_event_table_id_is_rejected(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $otherEvent = Event::factory()->create();
        $foreignTable = EventTable::factory()->for($otherEvent)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}", [
                'name' => $guest->name,
                'event_table_id' => $foreignTable->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_table_id');
    }

    public function test_accepted_manager_staff_can_update(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->patchJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}", ['name' => 'Manager Edited'])
            ->assertOk();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['name' => 'Untouched']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->patchJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertSame('Untouched', $guest->fresh()->name);
    }
}
