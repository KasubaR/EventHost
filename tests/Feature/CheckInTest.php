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

    /**
     * Starting now, on the venue's clock, puts the event squarely inside the
     * check-in window whatever wall-clock time the suite runs at. The factory's
     * random event_time made this flaky once the window gained a 12-hour tail.
     */
    private function eventOnToday(User $owner, array $overrides = []): Event
    {
        $localNow = now()->timezone(config('events.timezone'));

        return Event::factory()->for($owner)->create(array_merge([
            'event_date' => $localNow->toDateString(),
            'event_time' => $localNow->format('H:i:s'),
        ], $overrides));
    }

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
        $event = $this->eventOnToday($owner);
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
        $event = $this->eventOnToday($owner);
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
        $event = $this->eventOnToday($owner);
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

    /**
     * checked_in_at must keep the ORIGINAL arrival rather than being refreshed
     * to now(), for the same reason as the ticket scanner's twin test —
     * checkin-scanner.js puts it in the re-scan warning so door staff can see
     * how long ago the credential was first used.
     */
    public function test_confirming_an_already_checked_in_guest_is_idempotent(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
        $firstCheckIn = now()->subMinutes(5);
        $guest = Guest::factory()->for($event)->create(['checked_in_at' => $firstCheckIn]);

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => true]);
        $response->assertJsonPath('guest.checked_in_at', $firstCheckIn->toIso8601String());
    }

    public function test_token_from_a_different_event_is_rejected(): void
    {
        $owner = User::factory()->pro()->create();
        $eventA = $this->eventOnToday($owner);
        $eventB = $this->eventOnToday($owner);
        $guest = Guest::factory()->for($eventB)->create();

        $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $eventA, 'token' => $guest->invitation_token]))
            ->assertNotFound();
    }

    public function test_opening_a_check_in_qr_in_the_browser_goes_to_the_homepage(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
        $guest = Guest::factory()->for($event)->create();

        $qrUrl = $guest->checkInQrUrl();

        $this->assertSame(
            $qrUrl,
            route('events.checkin.qr-open', ['event' => $event, 'token' => $guest->invitation_token])
        );

        $this->actingAs($owner)
            ->get($qrUrl)
            ->assertRedirect(route('home'));

        $this->get($qrUrl)
            ->assertRedirect(route('home'));

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_guest_cannot_self_check_in_without_being_authenticated(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
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

    public function test_lookup_rejects_an_empty_or_one_character_query(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice Wonder']);

        $this->actingAs($owner)
            ->getJson(route('events.checkin.lookup', $event).'?q=')
            ->assertOk()
            ->assertJsonCount(0, 'guests');

        $this->actingAs($owner)
            ->getJson(route('events.checkin.lookup', $event).'?q=A')
            ->assertOk()
            ->assertJsonCount(0, 'guests');
    }

    public function test_check_in_is_refused_before_the_window_opens(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner, ['event_date' => now()->addDays(3)->toDateString()]);
        $guest = Guest::factory()->for($event)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertForbidden();
        $this->assertStringContainsString('Check-in opens', (string) $response->json('message'));

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_check_in_is_refused_after_the_window_closes(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner, ['event_date' => now()->subDays(3)->toDateString()]);
        $guest = Guest::factory()->for($event)->create();

        $response = $this->actingAs($owner)
            ->postJson(route('events.checkin.confirm-token', ['event' => $event, 'token' => $guest->invitation_token]));

        $response->assertForbidden();
        $this->assertStringContainsString('Check-in closed', (string) $response->json('message'));

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_scanner_page_hides_the_camera_outside_the_window(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create([
            'event_date' => now()->addWeek()->toDateString(),
        ]);

        $this->actingAs($owner)
            ->get(route('events.checkin.scan', $event))
            ->assertOk()
            ->assertSee('Check-in opens', escape: false)
            ->assertDontSee('ckinVideo', escape: false);
    }

    public function test_scanner_page_shows_the_camera_inside_the_window(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);

        $this->actingAs($owner)
            ->get(route('events.checkin.scan', $event))
            ->assertOk()
            ->assertSee('ckinVideo', escape: false)
            ->assertSee('Scan again', escape: false);
    }
}
