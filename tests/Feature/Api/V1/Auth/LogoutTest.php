<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_token_can_log_out_and_is_deleted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_token_no_longer_in_the_database_cannot_authenticate(): void
    {
        // Deletes the row directly (rather than through the logout endpoint + a second HTTP
        // call in this same test) because Illuminate\Auth\RequestGuard caches its resolved user
        // for the lifetime of the guard instance, which Laravel's test container reuses across
        // sequential calls within one test method — a second authenticated call here would
        // incorrectly see the first call's cached user regardless of what logout actually did.
        // Deletion itself is already asserted directly against the database above.
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $user->tokens()->delete();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_logout_without_a_token_is_unauthorized(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}
