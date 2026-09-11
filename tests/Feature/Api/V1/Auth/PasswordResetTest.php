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
}
