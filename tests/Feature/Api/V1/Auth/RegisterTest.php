<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'account_type' => 'individual',
            'name' => 'Jane Host',
            'email' => 'jane@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides);
    }

    public function test_individual_registration_returns_a_token_and_user(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'email', 'capabilities']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.account_type', 'individual');

        $user = User::where('email', 'jane@example.com')->firstOrFail();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($user, WelcomeNotification::class);
    }

    public function test_organisation_registration_overwrites_submitted_company_name_with_the_account_name(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'account_type' => 'organisation',
            'name' => 'Acme Events',
            'company_name' => 'Something Else Entirely',
        ]));

        $response->assertCreated()
            ->assertJsonPath('user.company_name', 'Acme Events');

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'company_name' => 'Acme Events',
        ]);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type', 'name', 'email', 'password']);
    }

    public function test_registration_is_throttled_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/register', $this->payload([
                'email' => "user{$i}@example.com",
            ]))->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'user5@example.com',
        ]))->assertStatus(429);
    }
}
