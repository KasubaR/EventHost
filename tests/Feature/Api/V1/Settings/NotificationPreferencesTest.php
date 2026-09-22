<?php

namespace Tests\Feature\Api\V1\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice E — PATCH /api/v1/settings/notifications. Proves the new push_* keys
 * (User::DEFAULT_NOTIFICATION_PREFERENCES) round-trip through /me the same
 * way the existing email_* ones do.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_toggling_a_push_preference_persists_and_is_reflected_on_me(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->assertTrue($user->notification_preferences['push_rsvp_updates']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(route('api.v1.settings.notifications.update'), [
                'notification_preferences' => ['push_rsvp_updates' => false],
            ])
            ->assertOk()
            ->assertJsonPath('user.notification_preferences.push_rsvp_updates', false);

        // Untouched keys keep their existing value — merge-over, not replace.
        $this->assertTrue($user->fresh()->notification_preferences['email_rsvp_updates']);
        $this->assertFalse($user->fresh()->notification_preferences['push_rsvp_updates']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.v1.me'))
            ->assertJsonPath('notification_preferences.push_rsvp_updates', false);
    }

    public function test_unknown_keys_are_silently_dropped(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson(route('api.v1.settings.notifications.update'), [
                'notification_preferences' => ['not_a_real_key' => true, 'name' => 'hijacked'],
            ])
            ->assertOk();

        $this->assertArrayNotHasKey('not_a_real_key', $user->fresh()->notification_preferences);
        $this->assertNotSame('hijacked', $user->fresh()->name);
    }
}
