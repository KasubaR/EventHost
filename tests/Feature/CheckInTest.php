<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckInTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_tier_owner_is_redirected_to_billing_from_scanner(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->get(route('events.checkin.scan', $event))
            ->assertRedirect(route('billing.show'));
    }

    public function test_owner_can_confirm_check_in_by_token(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => false]);
        $response->assertJsonPath('guest.name', $guest->name);

        $guest->refresh();
        $this->assertNotNull($guest->checked_in_at);
        $this->assertSame($owner->id, $guest->checked_in_by);
    }

    public function test_confirming_an_already_checked_in_guest_is_idempotent(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['checked_in_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => true]);
    }

    public function test_token_from_a_different_event_is_rejected(): void
    {
        $owner = User::factory()->pro()->create();
        $eventA = Event::factory()->for($owner)->create();
        $eventB = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($eventB)->create();

        $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $eventA, 'token' => $guest->invitation_token]))
            ->assertNotFound();
    }

    public function test_guest_cannot_self_check_in_without_being_authenticated(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->post(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]))
            ->assertRedirect(route('login'));

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_lookup_returns_matching_guests(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice Wonder']);
        Guest::factory()->for($event)->create(['name' => 'Bob Builder']);

        $response = $this->actingAs($owner)
            ->getJson(route('events.checkin.lookup', $event).'?q=Alice');

        $response->assertOk();
        $response->assertJsonCount(1, 'guests');
        $response->assertJsonPath('guests.0.name', 'Alice Wonder');
    }
}
