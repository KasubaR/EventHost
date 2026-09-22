<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Slice E — PUT /api/v1/settings/password. Validates current_password against
 * the 'web' guard explicitly (Api\V1\Settings\SecurityController's own
 * docblock explains why the implicit current_password rule can't be used
 * under a stateless Sanctum request).
 */
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_change_their_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson(route('api.v1.settings.password.update'), [
                'current_password' => 'OldPassword123',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123')]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson(route('api.v1.settings.password.update'), [
                'current_password' => 'WrongPassword',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }
}
