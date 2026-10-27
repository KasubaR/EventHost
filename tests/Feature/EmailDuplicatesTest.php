<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Notifications\EventUpdatedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/email-gaps.md Phase 4: the same update email is not sent twice by a double submit, a date or time change prompts the
 * host like a venue change does, and the update email restates the current details.
 */
class EmailDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', config('events.timezone'))->utc());
        Notification::fake();

        $this->host = User::factory()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'venue' => 'Garden Court',
        ]);
        $this->guest = Guest::factory()->for($this->event)->create(['email' => 'guest@example.test', 'invitation_sent' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sendUpdate(string $message)
    {
        return $this->actingAs($this->host)->post(route('events.guests.bulk', $this->event), [
            'action' => 'send_update_email',
            'guest_ids' => [$this->guest->id],
            'update_message' => $message,
        ]);
    }

    public function test_the_same_update_sent_twice_in_ten_minutes_goes_out_once(): void
    {
        $this->sendUpdate('We now start at 16:00.')->assertSessionHas('bulk_count', 1);
        $this->sendUpdate('We now start at 16:00.')
            ->assertSessionHas('bulk_count', 0)
            ->assertSessionHas('bulk_skipped', 1)
            ->assertSessionHas('bulk_skip_reason', '1 already sent this message in the last 10 minutes');

        Notification::assertSentOnDemandTimes(EventUpdatedNotification::class, 1);
    }

    public function test_a_different_message_or_a_later_resend_still_goes_out(): void
    {
        $this->sendUpdate('We now start at 16:00.');
        $this->sendUpdate('Parking is at the back.')->assertSessionHas('bulk_count', 1);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->sendUpdate('We now start at 16:00.')->assertSessionHas('bulk_count', 1);

        Notification::assertSentOnDemandTimes(EventUpdatedNotification::class, 3);
    }

    public function test_the_api_skips_a_repeated_update_too(): void
    {
        Sanctum::actingAs($this->host);
        $payload = ['action' => 'send_update_email', 'guest_ids' => [$this->guest->id], 'update_message' => 'Same text'];

        $this->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", $payload)->assertJsonPath('affected_count', 1);
        $this->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", $payload)
            ->assertJsonPath('affected_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped_reason', '1 already sent this message in the last 10 minutes');
    }

    public function test_the_update_email_restates_the_current_details(): void
    {
        $this->sendUpdate('We moved the party.');

        Notification::assertSentOnDemand(EventUpdatedNotification::class, function (EventUpdatedNotification $n, array $channels, object $notifiable) {
            $text = collect($n->toMail($notifiable)->introLines)->implode("\n");

            return str_contains($text, 'We moved the party.') && str_contains($text, 'Garden Court');
        });
    }

    private function updatePayload(array $overrides): array
    {
        return array_merge([
            'name' => $this->event->name,
            'event_type' => $this->event->event_type,
            'event_date' => '2026-11-20',
            'event_time' => '15:00',
            'venue' => 'Garden Court',
            'location_name' => $this->event->location_name,
            'is_public' => '1',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ], $overrides);
    }

    public function test_changing_the_date_prompts_the_host_to_tell_guests(): void
    {
        $this->actingAs($this->host)
            ->patchJson(route('events.update', $this->event), $this->updatePayload(['event_date' => '2026-11-27']))
            ->assertOk()
            ->assertJsonPath('notify_guests.count', 1)
            ->assertJsonPath('notify_guests.changed', 'You changed the date or time.');
    }

    public function test_changing_the_time_prompts_the_host_on_the_no_js_form_too(): void
    {
        $this->actingAs($this->host)
            ->patch(route('events.update', $this->event), $this->updatePayload(['event_time' => '17:30']))
            ->assertSessionHas('notify_guests_count', 1)
            ->assertSessionHas('notify_guests_changed', 'You changed the date or time.');
    }

    public function test_saving_the_same_time_written_without_seconds_prompts_nothing(): void
    {
        $this->actingAs($this->host)
            ->patchJson(route('events.update', $this->event), $this->updatePayload(['name' => 'Renamed']))
            ->assertOk()
            ->assertJsonMissingPath('notify_guests');
    }

    public function test_the_api_names_a_date_change(): void
    {
        Sanctum::actingAs($this->host);

        $this->patchJson("/api/v1/host/events/{$this->event->id}", ['event_date' => '2026-11-27', 'venue' => 'New Hall'])
            ->assertOk()
            ->assertJsonPath('notify_guests.count', 1)
            ->assertJsonPath('notify_guests.changed', 'You changed the date or time, and the venue or location.');
    }
}
