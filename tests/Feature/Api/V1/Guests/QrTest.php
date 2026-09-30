<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_pro_owner_gets_a_png(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/host/events/{$event->id}/guests/{$guest->id}/qr.png");

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith("\x89PNG", $response->getContent());
    }

    public function test_base_tier_owner_gets_the_premium_required_403(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}/qr.png")
            ->assertStatus(403)
            ->assertJsonPath('error', 'premium_required');
    }

    public function test_a_guest_without_an_invite_token_has_no_qr(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_token' => null]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$guest->id}/qr.png")
            ->assertNotFound();
    }

    public function test_a_guest_from_another_event_is_not_found(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $otherEvent = Event::factory()->for($owner)->create();
        $foreignGuest = Guest::factory()->for($otherEvent)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/guests/{$foreignGuest->id}/qr.png")
            ->assertNotFound();
    }
}
