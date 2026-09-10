<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Plan-driven guest-list size cap — Base 150, Pro 300, Pro+/Enterprise
 * unlimited (config/billing.php's guest_limit_default, enforced live by
 * Event::guestCapacity()). Distinct from the pre-existing `guest_limit`
 * field, which caps *accepted* attendees per event and is untouched here.
 */
class GuestCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_host_is_blocked_from_adding_a_guest_past_150(): void
    {
        $owner = User::factory()->create(); // default factory tier is Base
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->count(150)->for($event)->create();

        $this->assertSame(0, $event->remainingGuestCapacity());
        $this->assertTrue($event->hasReachedGuestCapacity());

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Guest 151',
                'plus_one_allowed' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('name');

        $this->assertSame(150, $event->guests()->count());
    }

    public function test_base_host_can_still_add_up_to_exactly_150(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->count(149)->for($event)->create();

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Guest 150',
                'plus_one_allowed' => '0',
            ])
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(150, $event->guests()->count());
    }

    public function test_pro_host_caps_at_300(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->count(300)->for($event)->create();

        $this->assertTrue($event->hasReachedGuestCapacity());

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Guest 301',
                'plus_one_allowed' => '0',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(300, $event->guests()->count());
    }

    public function test_pro_plus_host_has_no_cap(): void
    {
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->count(300)->for($event)->create();

        $this->assertNull($event->guestCapacity());
        $this->assertNull($event->remainingGuestCapacity());
        $this->assertFalse($event->hasReachedGuestCapacity());

        $this->actingAs($owner)
            ->post(route('events.guests.store', $event), [
                'name' => 'Guest 301',
                'plus_one_allowed' => '0',
            ])
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(301, $event->guests()->count());
    }

    public function test_ticketed_events_have_no_guest_capacity(): void
    {
        $event = Event::factory()->ticketed()->create();

        $this->assertNull($event->guestCapacity());
        $this->assertFalse($event->hasReachedGuestCapacity());
    }

    public function test_csv_import_stops_at_the_cap_and_reports_how_many_were_left_out(): void
    {
        $owner = User::factory()->create(); // Base, cap 150
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->count(148)->for($event)->create();

        $csv = "name,email\nAlpha,alpha@example.com\nBeta,beta@example.com\nGamma,gamma@example.com\n";
        $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

        $response = $this->actingAs($owner)
            ->post(route('events.guests.import.store', $event), ['file' => $file])
            ->assertRedirect(route('events.guests.index', $event));

        $response->assertSessionHas('import_created', 2);
        $response->assertSessionHas('import_skipped', 0);
        $response->assertSessionHas('import_capped', 1);

        $this->assertSame(150, $event->guests()->count());
        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'email' => 'alpha@example.com']);
        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'email' => 'beta@example.com']);
        $this->assertDatabaseMissing('guests', ['event_id' => $event->id, 'email' => 'gamma@example.com']);
    }

    public function test_guest_list_shows_the_plan_cap_and_upgrade_nudge_once_full(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->count(150)->for($event)->create();

        $this->actingAs($owner)
            ->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('150')
            ->assertSee('/ 150', false)
            ->assertSee('Pro');
    }
}
