<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
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

    public function test_a_key_absent_from_the_payload_leaves_the_stored_value_untouched(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['venue' => 'Original Venue']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['name' => 'Renamed'])
            ->assertOk();

        $event->refresh();
        $this->assertSame('Renamed', $event->name);
        $this->assertSame('Original Venue', $event->venue);
    }

    public function test_a_key_present_as_empty_string_clears_the_field(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['venue' => 'Original Venue']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['venue' => ''])
            ->assertOk();

        $this->assertNull($event->fresh()->venue);
    }

    public function test_ticketed_fields_are_stripped_for_a_ticketed_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create(['guest_limit' => null]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['guest_limit' => 50, 'is_public' => false])
            ->assertOk();

        $event->refresh();
        $this->assertNull($event->guest_limit);
        $this->assertTrue($event->is_public); // ticketed events are always public, untouched
    }

    public function test_notify_guests_count_is_returned_when_location_changes_on_a_live_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['venue' => 'Old Venue']);
        Guest::factory()->for($event)->create(['invitation_sent' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['venue' => 'New Venue']);

        $response->assertOk()
            ->assertJsonPath('notify_guests.count', 1);
    }

    public function test_notify_guests_is_null_when_nothing_location_related_changes(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['description' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('notify_guests', null);
    }

    public function test_non_owner_manager_staff_cannot_update_an_invitation_event(): void
    {
        // EventStaff is ticketed-events only per CLAUDE.md — a stranger has no
        // access to an invitation event's update endpoint at all.
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->patchJson("/api/v1/host/events/{$event->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertNotSame('Hijacked', $event->fresh()->name);
    }
}
