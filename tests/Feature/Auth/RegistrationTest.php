<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('verification.notice', absolute: false));

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('pending', $user->status);

        Notification::assertSentTo($user, WelcomeNotification::class);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Other User',
            'email' => 'taken@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors('email');
    }

    public function test_invalid_zambian_phone_number_is_rejected(): void
    {
        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Test User',
            'email' => 'phonetest@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '12345',
        ])->assertSessionHasErrors('phone');

        $this->assertNull(User::where('email', 'phonetest@example.com')->first());
    }

    public function test_valid_zambian_phone_number_is_accepted(): void
    {
        Notification::fake();

        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Test User',
            'email' => 'phoneok@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '+260 97 000 0000',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'phoneok@example.com')->first());
    }
}
