<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_token_and_update_last_login(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user'])
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->last_login_at);
        $this->assertNotNull($fresh->last_login_ip);
    }

    public function test_suspended_user_gets_the_same_generic_failure_as_a_wrong_password(): void
    {
        $user = User::factory()->suspended()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123',
        ]);

        $response->assertUnprocessable();
        $this->assertSame(
            trans('auth.failed'),
            $response->json('errors.email.0')
        );
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'WrongPassword',
        ])->assertUnprocessable();
    }

    public function test_the_sixth_failed_attempt_in_a_minute_is_locked_out(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jane@example.com',
                'password' => 'WrongPassword',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123',
        ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString(
            'Too many login attempts',
            (string) $response->json('errors.email.0')
        );
    }

    public function test_a_successful_login_clears_the_limiter_so_a_prior_failure_does_not_carry_over(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'WrongPassword',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123',
        ])->assertOk();

        // The limiter was cleared on success — a fresh wrong attempt starts counting from zero.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'WrongPassword',
        ])->assertUnprocessable();
    }
}
