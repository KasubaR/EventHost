<?php

namespace Tests\Feature\Api\V1\GuestGroups;

use App\Models\Event;
use App\Models\GuestGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * plans/group-rsvp-links.md — the API's additive fields for a group's shared RSVP link.
 */
class SeatPoolTest extends TestCase
{
    use RefreshDatabase;

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_an_ordinary_group_reports_null_link_fields(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        GuestGroup::factory()->for($event)->create();

        $this->withHeaders($this->auth($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guest-groups")
            ->assertOk()
            ->assertJsonPath('0.seat_limit', null)
            ->assertJsonPath('0.rsvp_link', null)
            ->assertJsonPath('0.seats_taken', null);
    }

    public function test_seat_limit_turns_the_link_on_changes_it_and_null_turns_it_off(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $headers = $this->auth($owner);

        $created = $this->withHeaders($headers)
            ->postJson("/api/v1/host/events/{$event->id}/guest-groups", ['name' => 'Committee', 'seat_limit' => 10])
            ->assertCreated()
            ->assertJsonPath('seat_limit', 10)
            ->assertJsonPath('seats_taken', 0)
            ->assertJsonPath('rsvp_link_closed', false)
            ->json();
        $this->assertStringContainsString('/g/', $created['rsvp_link']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/host/events/{$event->id}/guest-groups/{$created['id']}", ['name' => 'Committee', 'seat_limit' => 12])
            ->assertOk()
            ->assertJsonPath('seat_limit', 12)
            ->assertJsonPath('rsvp_link', $created['rsvp_link']);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/host/events/{$event->id}/guest-groups/{$created['id']}", ['name' => 'Committee', 'rsvp_link_closed' => true])
            ->assertOk()
            ->assertJsonPath('rsvp_link_closed', true);

        $this->withHeaders($headers)
            ->patchJson("/api/v1/host/events/{$event->id}/guest-groups/{$created['id']}", ['name' => 'Committee', 'seat_limit' => null])
            ->assertOk()
            ->assertJsonPath('seat_limit', null)
            ->assertJsonPath('rsvp_link', null);
    }

    public function test_a_rename_alone_leaves_the_link_untouched(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink(4);

        $this->withHeaders($this->auth($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/guest-groups/{$group->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('seat_limit', 4);
    }

    public function test_seat_limit_must_be_positive(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeaders($this->auth($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guest-groups", ['name' => 'Committee', 'seat_limit' => 0])
            ->assertUnprocessable();
    }

    public function test_the_event_resource_exposes_the_host_contact_number(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create(['host_contact_phone' => '0977 123 456']);

        $this->withHeaders($this->auth($owner))
            ->getJson("/api/v1/host/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('host_contact_phone', '0977 123 456');
    }
}
