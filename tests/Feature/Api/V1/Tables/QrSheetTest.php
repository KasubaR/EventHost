<?php

namespace Tests\Feature\Api\V1\Tables;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrSheetTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_pro_owner_gets_a_real_pdf(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        EventTable::factory()->for($event)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/host/events/{$event->id}/tables/qr-sheet.pdf");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_base_tier_owner_gets_the_unified_premium_required_403(): void
    {
        $owner = User::factory()->create(); // Base tier
        $event = Event::factory()->for($owner)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/host/events/{$event->id}/tables/qr-sheet.pdf");

        $response->assertStatus(403)
            ->assertJsonPath('error', 'premium_required')
            ->assertJsonPath('required_tier', 'pro');
    }
}
