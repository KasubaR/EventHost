<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspendedAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suspended_users_token_is_revoked_and_rejected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $event = Event::factory()->for($user)->create();
        $token = $user->createToken('test')->plainTextToken;

        // Sanity check: the token works before suspension.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/host/events/{$event->id}")
            ->assertOk();

        // status is intentionally not in User::$fillable (an admin-only column) —
        // ->update() would silently no-op it. Direct property assignment bypasses
        // mass-assignment protection, same as the admin action that does this for real.
        $user->status = 'suspended';
        $user->save();

        // Illuminate\Auth\RequestGuard caches its resolved user for the guard
        // instance's lifetime, and Laravel's test container reuses that instance
        // across sequential calls within one test method — without forgetting it,
        // this second call would see the first call's stale (pre-suspension) user.
        auth()->forgetGuards();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/host/dashboard');

        $response->assertStatus(403);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_an_active_users_token_is_unaffected(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/host/dashboard')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());
    }
}
