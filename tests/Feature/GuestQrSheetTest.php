<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestQrSheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_pro_owner_can_download_the_guest_qr_sheet(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice Wonder']);

        $response = $this->actingAs($owner)->get(route('events.guests.qr-sheet', $event));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    /**
     * The owner is allowed here, they just haven't paid for it — so this sends
     * them to billing rather than 403ing. A stranger still gets a hard 403,
     * see the next test.
     */
    public function test_base_tier_owner_is_sent_to_billing_instead_of_the_qr_sheet(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create();

        $this->actingAs($owner)
            ->get(route('events.guests.qr-sheet', $event))
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', 'premium-required-qr-badges');
    }

    public function test_base_tier_owner_still_cannot_fetch_a_single_guest_qr_image(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        // An image endpoint, not a page — redirecting it to billing would just
        // hand back HTML where an SVG was expected.
        $this->actingAs($owner)
            ->get(route('events.guests.qr', ['event' => $event, 'guest' => $guest]))
            ->assertForbidden();
    }

    public function test_non_owner_cannot_download_another_hosts_guest_qr_sheet(): void
    {
        $owner = User::factory()->pro()->create();
        $stranger = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->get(route('events.guests.qr-sheet', $event))
            ->assertForbidden();
    }
}
