<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\Rsvp;
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

    public function test_confirm_response_includes_contact_table_and_rsvp_details(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create(['label' => 'Table 7']);
        $guest = Guest::factory()->for($event)->create([
            'email' => 'guest@example.test',
            'phone' => '+260971234567',
            'event_table_id' => $table->id,
        ]);
        Rsvp::factory()->for($guest)->create([
            'status' => RsvpStatus::Accepted,
            'meal_preference' => 'Vegetarian',
            'message' => 'Arriving with a wheelchair, please have a ramp ready.',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertOk();
        $response->assertJsonPath('guest.email', 'guest@example.test');
        $response->assertJsonPath('guest.phone', '+260971234567');
        $response->assertJsonPath('guest.table', 'Table 7');
        $response->assertJsonPath('guest.meal_preference', 'Vegetarian');
        $response->assertJsonPath('guest.rsvp_note', 'Arriving with a wheelchair, please have a ramp ready.');
    }

    public function test_confirm_response_omits_details_the_guest_does_not_have(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['email' => null, 'phone' => null, 'event_table_id' => null]);

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertOk();
        $response->assertJsonPath('guest.email', null);
        $response->assertJsonPath('guest.phone', null);
        $response->assertJsonPath('guest.table', null);
        $response->assertJsonPath('guest.meal_preference', null);
        $response->assertJsonPath('guest.rsvp_note', null);
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
