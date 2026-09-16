<?php

namespace Tests\Unit\Notifications;

use App\Models\DeviceToken;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\NewRsvpReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice E — via() independently gates 'mail' on email_rsvp_updates and
 * FcmChannel::class on (push_rsvp_updates AND at least one DeviceToken), so a
 * host with one channel off still gets the other. See
 * App\Services\CommunicationService::notifyHostNewRsvp()'s matching gate
 * change.
 */
class NewRsvpReceivedNotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    private function notification(): NewRsvpReceivedNotification
    {
        $event = Event::factory()->create();
        $guest = Guest::factory()->for($event)->create();
        $rsvp = Rsvp::factory()->for($guest)->create();

        return new NewRsvpReceivedNotification($event, $guest, $rsvp);
    }

    public function test_both_channels_fire_by_default_with_a_device_token(): void
    {
        $user = User::factory()->create();
        DeviceToken::factory()->for($user)->create();

        $channels = $this->notification()->via($user);

        $this->assertContains('mail', $channels);
        $this->assertContains(FcmChannel::class, $channels);
    }

    public function test_push_is_omitted_with_no_registered_device(): void
    {
        $user = User::factory()->create();

        $channels = $this->notification()->via($user);

        $this->assertContains('mail', $channels);
        $this->assertNotContains(FcmChannel::class, $channels);
    }

    public function test_mail_off_and_push_on_still_sends_push_only(): void
    {
        $user = User::factory()->create();
        DeviceToken::factory()->for($user)->create();
        $user->update(['notification_preferences' => array_merge(
            $user->notification_preferences,
            ['email_rsvp_updates' => false],
        )]);

        $channels = $this->notification()->via($user->fresh());

        $this->assertNotContains('mail', $channels);
        $this->assertContains(FcmChannel::class, $channels);
    }

    public function test_push_off_and_mail_on_still_sends_mail_only(): void
    {
        $user = User::factory()->create();
        DeviceToken::factory()->for($user)->create();
        $user->update(['notification_preferences' => array_merge(
            $user->notification_preferences,
            ['push_rsvp_updates' => false],
        )]);

        $channels = $this->notification()->via($user->fresh());

        $this->assertContains('mail', $channels);
        $this->assertNotContains(FcmChannel::class, $channels);
    }
}
