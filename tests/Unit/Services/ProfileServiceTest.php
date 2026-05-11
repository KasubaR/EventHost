<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_change_notifies_previous_address_and_queues_verification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'before@example.com']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'after@example.com',
        ]);

        $user->refresh();
        $this->assertSame('after@example.com', $user->email);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentOnDemand(
            EmailChangedNotification::class,
            fn (EmailChangedNotification $notification): bool => $notification->newEmail === 'after@example.com'
                && $notification->userName === $user->name
        );
    }
}
