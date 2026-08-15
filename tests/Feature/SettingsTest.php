<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_settings_tab_is_displayed(): void
    {
        $user = User::factory()->create();

        foreach (['/settings/profile', '/settings/security', '/settings/notifications', '/settings/account'] as $path) {
            $this->actingAs($user)->get($path)->assertOk();
        }
    }

    public function test_settings_root_redirects_to_the_profile_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings')
            ->assertRedirect('/settings/profile');
    }

    public function test_the_old_profile_url_redirects_to_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertStatus(301)
            ->assertRedirect('/settings/profile');
    }

    public function test_settings_tabs_require_authentication(): void
    {
        foreach (['/settings/profile', '/settings/security', '/settings/notifications', '/settings/account'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_profile_information_can_be_updated(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_photo_can_be_uploaded(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick is required for profile photo tests.');
        }

        Notification::fake();
        Storage::fake('public');

        $user = User::factory()->create();

        $file = UploadedFile::fake()->image('avatar.jpg', 120, 120);

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'profile_photo' => $file,
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

        $user->refresh();
        $this->assertNotNull($user->profile_photo);
        Storage::disk('public')->assertExists($user->profile_photo);
    }

    public function test_profile_photo_replace_removes_previous_file(): void
    {
        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick is required for profile photo tests.');
        }

        Notification::fake();
        Storage::fake('public');

        $user = User::factory()->create();
        $oldPath = 'profile-photos/'.$user->id.'-111.webp';
        Storage::disk('public')->put($oldPath, 'fake-webp-bytes');
        $user->forceFill(['profile_photo' => $oldPath])->save();

        $file = UploadedFile::fake()->image('new.jpg', 120, 120);

        $this->actingAs($user)->patch('/settings/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'profile_photo' => $file,
        ])->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

        Storage::disk('public')->assertMissing($oldPath);
        $user->refresh();
        $this->assertNotSame($oldPath, $user->profile_photo);
        Storage::disk('public')->assertExists($user->profile_photo);
    }

    public function test_notification_preferences_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/notifications', [
                'notification_preferences' => [
                    'email_marketing' => '1',
                    'sms_reminders' => '0',
                ],
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/notifications');

        $prefs = $user->refresh()->notification_preferences;

        $this->assertTrue($prefs['email_marketing']);
        $this->assertFalse($prefs['sms_reminders']);
        // Keys left out of the request keep their stored value.
        $this->assertTrue($prefs['email_rsvp_updates']);
    }

    public function test_preferences_can_be_saved_without_sending_profile_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/notifications', [
                'notification_preferences' => ['email_marketing' => '1'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/notifications');

        $this->assertTrue($user->refresh()->notification_preferences['email_marketing']);
    }

    /**
     * The endpoint validates preferences only, so profile columns smuggled into
     * the payload must be ignored rather than written.
     */
    public function test_saving_preferences_cannot_change_the_email_or_name(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'name' => 'Chanda Mwansa',
            'email' => 'host@example.com',
        ]);
        $verifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patch('/settings/notifications', [
                'name' => 'Attacker',
                'email' => 'attacker@example.com',
                'notification_preferences' => ['email_marketing' => '1'],
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Chanda Mwansa', $user->name);
        $this->assertSame('host@example.com', $user->email);
        $this->assertEquals($verifiedAt, $user->email_verified_at);
        $this->assertTrue($user->notification_preferences['email_marketing']);

        Notification::assertNothingSent();
    }

    public function test_unknown_preference_keys_are_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/notifications', [
                'notification_preferences' => [
                    'email_marketing' => '1',
                    'not_a_real_preference' => '1',
                ],
            ])
            ->assertSessionHasNoErrors();

        $prefs = $user->refresh()->notification_preferences;

        $this->assertArrayNotHasKey('not_a_real_preference', $prefs);
        $this->assertSame(
            array_keys(User::defaultNotificationPreferences()),
            array_keys($prefs)
        );
    }

    public function test_the_notifications_form_no_longer_carries_profile_fields(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/settings/notifications')->getContent();

        $this->assertStringNotContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="email"', $html);
    }

    public function test_a_failed_password_change_returns_to_the_security_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/security')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/settings/security');
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/account', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/account')
            ->delete('/settings/account', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/settings/account');

        $this->assertNotNull($user->fresh());
    }
}
