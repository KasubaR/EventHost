<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\GuestGroup;
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

    public function test_owner_gets_the_guest_with_groups_tables_and_capabilities(): void
    {
        config(['communications.whatsapp.enabled' => true]);
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $group = GuestGroup::factory()->for($event)->create(['name' => 'Family']);
        $guest = Guest::factory()->for($event)->create(['name' => 'Alice', 'guest_group_id' => $group->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonStructure([
                'guest' => ['id', 'name', 'personal_rsvp_url', 'check_in_qr_url', 'invitation_sent_at'],
                'groups', 'tables',
                'capabilities' => ['premium_tools', 'whatsapp_send_enabled'],
            ])
            ->assertJsonPath('guest.name', 'Alice')
            ->assertJsonPath('guest.group_name', 'Family')
            ->assertJsonPath('groups.0.name', 'Family')
            ->assertJsonPath('capabilities.premium_tools', true)
            ->assertJsonPath('capabilities.whatsapp_send_enabled', true);
    }

    public function test_capabilities_are_off_for_a_base_tier_owner(): void
    {
        config(['communications.whatsapp.enabled' => false]);
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertOk()
            ->assertJsonPath('capabilities.premium_tools', false)
            ->assertJsonPath('capabilities.whatsapp_send_enabled', false)
            ->assertJsonPath('guest.check_in_qr_url', null);
    }

    public function test_index_payload_also_carries_capabilities(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests")
            ->assertOk()
            ->assertJsonStructure(['capabilities' => ['premium_tools', 'whatsapp_send_enabled']]);
    }

    public function test_accepted_manager_staff_can_view(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertOk();
    }

    public function test_stranger_is_forbidden(): void
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor(User::factory()->create()))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}")
            ->assertForbidden();
    }

    public function test_a_guest_from_another_event_is_not_found(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $otherEvent = Event::factory()->for($owner)->create();
        $foreignGuest = Guest::factory()->for($otherEvent)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$foreignGuest->id}")
            ->assertNotFound();
    }
}
