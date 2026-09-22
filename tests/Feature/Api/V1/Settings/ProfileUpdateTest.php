<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice E — PATCH/DELETE /api/v1/settings/profile[/photo]. JSON sibling of
 * App\Http\Controllers\Settings\ProfileController, reusing UpdateProfileRequest
 * and ProfileService verbatim.
 */
class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_update_their_profile_and_me_reflects_it(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);
        $token = $this->tokenFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(route('api.v1.settings.profile.update'), [
                'name' => 'New Name',
                'email' => $user->email,
                'phone' => null,
                'company_name' => null,
            ]);

        $response->assertOk()->assertJsonPath('user.name', 'New Name');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.v1.me'))
            ->assertJsonPath('name', 'New Name');
    }

    public function test_changing_email_requires_reverification(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $user->forceFill(['email_verified_at' => now()])->save();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(route('api.v1.settings.profile.update'), [
                'name' => $user->name,
                'email' => 'new@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('user.email_verified_at', null);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->patchJson(route('api.v1.settings.profile.update'), ['name' => 'x'])
            ->assertUnauthorized();
    }
}
