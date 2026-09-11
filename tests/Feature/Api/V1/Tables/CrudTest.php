<?php

namespace Tests\Feature\Api\V1\Tables;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\EventTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrudTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_pro_owner_can_create_rename_and_delete_a_table(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $token = 'Bearer '.$this->tokenFor($owner);

        $created = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/host/events/{$event->id}/tables", ['label' => 'Table 1'])
            ->assertCreated()->json();

        $this->assertNotEmpty($created['code']);
        $this->assertNotNull($created['qr_payload_url']);

        $this->withHeader('Authorization', $token)
            ->patchJson("/api/v1/host/events/{$event->id}/tables/{$created['id']}", ['label' => 'Table 1 Renamed'])
            ->assertOk()
            ->assertJsonPath('label', 'Table 1 Renamed');

        $this->withHeader('Authorization', $token)
            ->deleteJson("/api/v1/host/events/{$event->id}/tables/{$created['id']}")
            ->assertNoContent();

        $this->assertNull(EventTable::find($created['id']));
    }

    public function test_base_tier_owner_gets_the_unified_403_on_store(): void
    {
        $owner = User::factory()->create(); // Base tier
        $event = Event::factory()->for($owner)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/tables", ['label' => 'Nope']);

        $response->assertStatus(403)
            ->assertJsonPath('error', 'premium_required')
            ->assertJsonPath('required_tier', 'pro');
    }

    public function test_base_tier_owner_gets_the_unified_403_on_update(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $table = EventTable::factory()->for($event)->create();

        // Downgrade after creating the table. subscription_tier is intentionally not in
        // User::$fillable (an admin/billing-only column) — ->update() would silently no-op it.
        $owner->subscription_tier = \App\Enums\SubscriptionTier::Base;
        $owner->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->patchJson("/api/v1/host/events/{$event->id}/tables/{$table->id}", ['label' => 'Renamed'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'premium_required');
    }

    public function test_accepted_manager_staff_can_manage_tables(): void
    {
        $owner = User::factory()->pro()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->postJson("/api/v1/host/events/{$event->id}/tables", ['label' => 'Manager Table'])
            ->assertCreated();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->pro()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/host/events/{$event->id}/tables", ['label' => 'Sneaky'])
            ->assertForbidden();
    }
}
