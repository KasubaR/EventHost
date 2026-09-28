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

    public function test_duplicate_email_with_different_capitalization_is_rejected(): void
    {
        User::factory()->create(['email' => 'john@email.com']);

        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Other User',
            'email' => 'John@Email.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors([
            'email' => 'An account with this email already exists.',
        ]);

        $this->assertSame(1, User::where('email', 'john@email.com')->count());
    }

    public function test_mixed_case_email_is_stored_lowercase(): void
    {
        Notification::fake();

        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'John Doe',
            'email' => '  John@Email.com  ',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('email', 'john@email.com')->first());
        $this->assertSame(1, User::count());

        $this->post('/logout');

        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Other User',
            'email' => 'john@email.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors('email');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Test User',
            'email' => 'not-an-email',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasErrors('email');

        $this->assertNull(User::where('email', 'not-an-email')->first());
        $this->assertSame(0, User::count());
    }

    public function test_empty_required_fields_are_rejected(): void
    {
        $this->post('/register', [
            'account_type' => 'individual',
            'name' => '',
            'email' => '',
            'password' => '',
            'password_confirmation' => '',
        ])->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertSame(0, User::count());
    }

    public function test_password_below_minimum_length_is_rejected(): void
    {
        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Test User',
            'email' => 'shortpw@example.com',
            'password' => 'Ab1',
            'password_confirmation' => 'Ab1',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'shortpw@example.com')->first());
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->post('/register', [
            'account_type' => 'individual',
            'name' => 'Test User',
            'email' => 'mismatch@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password456!',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'mismatch@example.com')->first());
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
