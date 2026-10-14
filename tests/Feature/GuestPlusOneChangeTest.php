<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\EventUpdatedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-editing-gaps.md Phase 4: switching a guest's plus-one on or off says what it does and does not do, the host can tell a guest
 * who already said yes (one email, never automatic), and the API reports whether a plus-one is actually available.
 */
class GuestPlusOneChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->host = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'is_public' => true,
            'allow_plus_one' => true,
            'rsvp_deadline' => null,
            'event_date' => now()->addDays(30)->toDateString(),
            'event_time' => '15:00:00',
            'guest_limit' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guest(array $overrides = [], ?int $seats = 1, RsvpStatus $status = RsvpStatus::Accepted): Guest
    {
        $guest = Guest::factory()->for($this->event)->create(array_merge([
            'name' => 'Plus Guest',
            'email' => 'plus@example.test',
            'plus_one_allowed' => false,
        ], $overrides));

        if ($seats !== null) {
            Rsvp::factory()->for($guest)->create(['status' => $status, 'attendee_count' => $status === RsvpStatus::Accepted ? $seats : 0]);
        }

        return $guest;
    }

    private function edit(Guest $guest, array $changes = [])
    {
        return $this->actingAs($this->host)->patch(
            route('events.guests.update', ['event' => $this->event, 'guest' => $guest]),
            array_merge(['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone], $changes),
        );
    }

    // ── switching it on ────────────────────────────────────────────────────────────────────────────

    public function test_switching_it_on_for_a_guest_who_said_yes_offers_to_tell_them(): void
    {
        $guest = $this->guest();

        $this->edit($guest, ['plus_one_allowed' => '1'])
            ->assertSessionHas('plus_one_offered', fn (array $o) => $o['guest'] === 'Plus Guest'
                && $o['has_email'] === true
                && $o['url'] === route('events.guests.notify-plus-one', ['event' => $this->event, 'guest' => $guest]))
            ->assertSessionMissing('plus_one_inactive');

        $this->assertTrue($guest->fresh()->plus_one_allowed);
        Notification::assertNothingSent();
    }

    public function test_the_list_shows_the_offer_with_a_button(): void
    {
        $guest = $this->guest();

        $this->actingAs($this->host)
            ->followingRedirects()
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'Plus Guest', 'email' => 'plus@example.test', 'plus_one_allowed' => '1'])
            ->assertSee('can now bring a plus-one')
            ->assertSee('Tell them');
    }

    public function test_a_guest_with_no_email_is_told_to_be_told_in_person(): void
    {
        $guest = $this->guest(['email' => null, 'phone' => '0971234567']);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'Plus Guest', 'phone' => '0971234567', 'plus_one_allowed' => '1'])
            ->assertSee('no email address')
            ->assertDontSee('Tell them');
    }

    public function test_no_offer_when_it_would_mean_nothing_to_the_guest(): void
    {
        foreach ([
            'no answer yet' => $this->guest(['email' => 'a@example.test'], null),
            'declined' => $this->guest(['email' => 'b@example.test'], 1, RsvpStatus::Declined),
            'already has two seats' => $this->guest(['email' => 'c@example.test'], 2),
            'already allowed' => $this->guest(['email' => 'd@example.test', 'plus_one_allowed' => true]),
        ] as $why => $guest) {
            $this->edit($guest, ['plus_one_allowed' => '1'])->assertSessionMissing('plus_one_offered');
        }
    }

    public function test_when_the_event_switch_is_off_the_tick_is_flagged_as_having_no_effect(): void
    {
        $this->event->forceFill(['allow_plus_one' => false])->save();
        $guest = $this->guest();

        $this->edit($guest, ['plus_one_allowed' => '1'])
            ->assertSessionHas('plus_one_inactive', 'Plus Guest')
            ->assertSessionMissing('plus_one_offered');

        $other = $this->guest(['name' => 'Other Guest', 'email' => 'other@example.test']);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $other]), ['name' => 'Other Guest', 'email' => 'other@example.test', 'plus_one_allowed' => '1'])
            ->assertSee('plus-ones are switched off for this event');
    }

    public function test_the_edit_page_explains_a_switched_off_event(): void
    {
        $guest = $this->guest();

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertOk()
            ->assertDontSee('Plus-ones are switched off for this event');

        $this->event->forceFill(['allow_plus_one' => false])->save();

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertSee('Plus-ones are switched off for this event, so this has no effect');
    }

    // ── tell them ──────────────────────────────────────────────────────────────────────────────────

    private function tell(Guest $guest, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->host)->post(route('events.guests.notify-plus-one', ['event' => $this->event, 'guest' => $guest]));
    }

    public function test_tell_them_sends_one_email_with_their_link(): void
    {
        $guest = $this->guest(['plus_one_allowed' => true]);

        $this->tell($guest)
            ->assertRedirect(route('events.guests.index', $this->event))
            ->assertSessionHas('status', 'plus-one-offer-sent')
            ->assertSessionHas('plus_one_told', 'Plus Guest');

        Notification::assertSentOnDemand(EventUpdatedNotification::class, function ($notification, $channels, $notifiable) use ($guest) {
            return $notifiable->routes['mail'] === 'plus@example.test'
                && str_contains($notification->updateMessage, 'bring one guest')
                && $notification->guest->is($guest);
        });
        $this->assertSame(1, NotificationLog::query()->where('guest_id', $guest->id)->where('type', 'plus_one_offer')->count());
    }

    public function test_it_is_not_sent_twice_within_an_hour_but_can_be_after(): void
    {
        $guest = $this->guest(['plus_one_allowed' => true]);

        $this->tell($guest)->assertSessionHas('status', 'plus-one-offer-sent');
        $this->tell($guest)->assertSessionHasErrors(['plus_one' => 'Plus Guest was already told in the last hour.']);

        Notification::assertSentOnDemandTimes(EventUpdatedNotification::class, 1);

        Carbon::setTestNow(now()->addMinutes(61));
        $this->tell($guest)->assertSessionHas('status', 'plus-one-offer-sent');

        Notification::assertSentOnDemandTimes(EventUpdatedNotification::class, 2);
    }

    public function test_a_bulk_update_email_does_not_count_as_having_told_them(): void
    {
        $guest = $this->guest(['plus_one_allowed' => true]);
        NotificationLog::query()->create([
            'event_id' => $this->event->id, 'guest_id' => $guest->id, 'channel' => 'email', 'type' => 'event_update',
            'status' => NotificationLog::STATUS_SENT, 'sent_at' => now(),
        ]);

        $this->tell($guest)->assertSessionHas('status', 'plus-one-offer-sent');
    }

    public function test_it_is_refused_when_the_plus_one_is_not_available_or_cannot_be_delivered(): void
    {
        $notAllowed = $this->guest(['plus_one_allowed' => false]);
        $this->tell($notAllowed)->assertSessionHasErrors('plus_one');

        $noEmail = $this->guest(['email' => null, 'phone' => '0971234567', 'plus_one_allowed' => true]);
        $this->tell($noEmail)->assertSessionHasErrors(['plus_one' => 'Plus Guest has no email address, so tell them yourself.']);

        $this->event->forceFill(['allow_plus_one' => false])->save();
        $this->tell($this->guest(['email' => 'x@example.test', 'plus_one_allowed' => true]))->assertSessionHasErrors('plus_one');

        $this->event->forceFill(['allow_plus_one' => true, 'rsvp_closed_at' => now()])->save();
        $closed = $this->guest(['email' => 'closed@example.test', 'plus_one_allowed' => true]);
        $this->tell($closed)->assertSessionHasErrors('plus_one');

        Notification::assertNothingSent();
    }

    public function test_only_the_owner_can_tell_a_guest(): void
    {
        $guest = $this->guest(['plus_one_allowed' => true]);

        $this->tell($guest, User::factory()->create())->assertForbidden();

        Notification::assertNothingSent();
    }

    // ── API ────────────────────────────────────────────────────────────────────────────────────────

    public function test_the_api_says_whether_a_plus_one_can_actually_be_taken(): void
    {
        $allowed = $this->guest(['plus_one_allowed' => true], null);
        $notAllowed = $this->guest(['email' => 'n@example.test', 'plus_one_allowed' => false], null);
        Sanctum::actingAs($this->host);

        $this->getJson(route('api.v1.host.events.guests.show', ['event' => $this->event, 'guest' => $allowed]))
            ->assertOk()
            ->assertJsonPath('guest.plus_one_allowed', true)
            ->assertJsonPath('guest.plus_one_effective', true);

        $this->getJson(route('api.v1.host.events.guests.show', ['event' => $this->event, 'guest' => $notAllowed]))
            ->assertJsonPath('guest.plus_one_effective', false);

        $this->event->forceFill(['allow_plus_one' => false])->save();

        $this->getJson(route('api.v1.host.events.guests.show', ['event' => $this->event, 'guest' => $allowed]))
            ->assertJsonPath('guest.plus_one_allowed', true)
            ->assertJsonPath('guest.plus_one_effective', false);
    }

    public function test_the_api_update_reports_a_plus_one_that_was_kept(): void
    {
        $holds = $this->guest(['plus_one_allowed' => true], 2);
        $solo = $this->guest(['email' => 's@example.test', 'plus_one_allowed' => true], 1);
        Sanctum::actingAs($this->host);

        $this->patchJson(route('api.v1.host.events.guests.update', ['event' => $this->event, 'guest' => $holds]), [
            'name' => 'Plus Guest', 'email' => 'plus@example.test', 'plus_one_allowed' => false,
        ])
            ->assertOk()
            ->assertJsonPath('plus_one_allowed', false)
            ->assertJsonPath('plus_one_kept', true);
        $this->assertSame(2, (int) $holds->fresh()->rsvp->attendee_count);

        $this->patchJson(route('api.v1.host.events.guests.update', ['event' => $this->event, 'guest' => $solo]), [
            'name' => 'Plus Guest', 'email' => 's@example.test', 'plus_one_allowed' => false,
        ])->assertJsonPath('plus_one_kept', false);
    }

    public function test_the_existing_web_flash_for_a_kept_plus_one_still_works(): void
    {
        $holds = $this->guest(['plus_one_allowed' => true], 2);

        $this->edit($holds, ['plus_one_allowed' => '0'])->assertSessionHas('plus_one_kept', 'Plus Guest');
    }
}
