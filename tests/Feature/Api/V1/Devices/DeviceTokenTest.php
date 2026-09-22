<?php

namespace Tests\Feature\Api\V1\Devices;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice E — POST/DELETE /api/v1/devices. New surface, no web equivalent —
 * proves the slice's own "Done when" bar: idempotent on (user_id, fcm_token).
 */
class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_registering_the_same_token_twice_updates_one_row(): void
    {
        $user = User::factory()->create();
        $authToken = $this->tokenFor($user);

        $payload = ['fcm_token' => 'abc123', 'platform' => 'android'];

        $this->withHeader('Authorization', 'Bearer '.$authToken)
            ->postJson(route('api.v1.devices.store'), $payload)
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$authToken)
            ->postJson(route('api.v1.devices.store'), $payload)
            ->assertCreated();

        $this->assertSame(1, DeviceToken::query()->where('fcm_token', 'abc123')->count());
    }

    /**
     * The first registration is seeded directly (not via a prior HTTP call
     * authenticated as a different user) — Illuminate\Auth\RequestGuard
     * caches its resolved user for the guard instance's lifetime, which
     * Laravel's test container reuses across calls within one test method,
     * so two HTTP calls authenticated as two different users in one method
     * would unreliably see the first user's cached auth on the second call
     * (same reasoning tests/Feature/Api/V1/Auth/LogoutTest.php documents).
     */
    public function test_a_token_re_registered_under_a_different_account_is_reassigned(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        DeviceToken::factory()->for($firstUser)->create(['fcm_token' => 'shared-token']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($secondUser))
            ->postJson(route('api.v1.devices.store'), ['fcm_token' => 'shared-token', 'platform' => 'android'])
            ->assertCreated();

        $token = DeviceToken::query()->where('fcm_token', 'shared-token')->sole();
        $this->assertSame($secondUser->id, $token->user_id);
    }

    public function test_deregistering_removes_only_the_callers_own_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        DeviceToken::factory()->for($user)->create(['fcm_token' => 'mine']);
        DeviceToken::factory()->for($other)->create(['fcm_token' => 'not-mine']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson(route('api.v1.devices.destroy'), ['fcm_token' => 'not-mine'])
            ->assertOk();

        // Not this user's token — left untouched.
        $this->assertNotNull(DeviceToken::query()->where('fcm_token', 'not-mine')->first());

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson(route('api.v1.devices.destroy'), ['fcm_token' => 'mine'])
            ->assertOk();

        $this->assertNull(DeviceToken::query()->where('fcm_token', 'mine')->first());
    }
}
