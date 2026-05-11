<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_defaults_notification_preferences_and_pending_status(): void
    {
        $user = User::factory()->unverified()->create([
            'notification_preferences' => null,
        ]);

        $user->refresh();

        $this->assertSame('pending', $user->status);
        $this->assertIsArray($user->notification_preferences);
        $this->assertSame(User::defaultNotificationPreferences(), $user->notification_preferences);
    }

    public function test_is_active_reflects_status(): void
    {
        $active = User::factory()->create(['status' => 'active']);
        $pending = User::factory()->unverified()->create(['status' => 'pending']);

        $this->assertTrue($active->isActive());
        $this->assertFalse($pending->isActive());
    }

    public function test_profile_photo_url_uses_default_when_missing(): void
    {
        $user = User::factory()->create(['profile_photo' => null]);

        $this->assertStringEndsWith('images/default-avatar.png', $user->profile_photo_url);
    }
}
