<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_reset_link_for_a_known_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jane@example.com'])
            ->assertOk()
            ->assertJsonStructure(['status']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_for_an_unknown_email_returns_a_validation_error(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_valid_reset_token_changes_the_password(): void
    {
        Event::fake([PasswordReset::class]);
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('OldPassword123'),
        ]);

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk()->assertJsonStructure(['status']);

        Event::assertDispatched(PasswordReset::class);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'NewPassword123',
        ])->assertOk();
    }

    public function test_an_invalid_reset_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'jane@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('OldPassword123'),
        ]);

        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_reset_token_cannot_be_reused_after_success(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('OldPassword123'),
        ]);

        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'AnotherPassword123',
            'password_confirmation' => 'AnotherPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_old_reset_token_is_rejected_after_a_newer_request(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('OldPassword123'),
        ]);

        $oldToken = Password::createToken($user);
        $newToken = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $oldToken,
            'email' => 'jane@example.com',
            'password' => 'HackedPassword123',
            'password_confirmation' => 'HackedPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $newToken,
            'email' => 'jane@example.com',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_password_reset_request_is_throttled(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jane@example.com'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jane@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
