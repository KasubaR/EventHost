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

    public function test_base_tier_owner_cannot_download_the_guest_qr_sheet(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        Guest::factory()->for($event)->create();

        $this->actingAs($owner)
            ->get(route('events.guests.qr-sheet', $event))
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
