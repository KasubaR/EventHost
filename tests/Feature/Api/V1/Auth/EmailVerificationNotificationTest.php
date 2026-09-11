<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unverified_user_gets_the_notification_resent(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_an_already_verified_user_gets_a_status_without_resending(): void
    {
        Notification::fake();

        $user = User::factory()->create(); // factory default: email_verified_at = now()
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('status', 'already-verified');

        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
    }

    public function test_the_seventh_request_in_a_minute_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('test')->plainTextToken;

        for ($i = 0; $i < 6; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/auth/email/verification-notification')
                ->assertOk();
        }

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertStatus(429);
    }
}
