<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\GuestRemoval;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\GuestRemovedNotification;
use App\Services\GuestDeleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-deletion-gaps.md Phase 5: what the host is told before and after removing a guest, the removed-guests list, and
 * the one "Tell them" email.
 */
class GuestRemovalHostSideTest extends TestCase
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
            'event_date' => now()->addMonth()->toDateString(),
            'is_public' => false,
            'host_contact_phone' => '0977000111',
        ]);
    }

    private function guest(array $attributes = []): Guest
    {
        return Guest::factory()->for($this->event)->create($attributes);
    }

    private function accepted(Guest $guest, int $seats = 1): Guest
    {
        Rsvp::factory()->forGuest($guest)->accepted($seats)->create();

        return $guest->fresh(['rsvp']);
    }

    private function remove(Guest $guest)
    {
        return $this->actingAs($this->host)
            ->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $guest->id]));
    }

    // ---------------------------------------------------------------- the confirm text

    public function test_the_warning_says_what_will_be_lost(): void
    {
        $plain = $this->guest(['name' => 'Plain Person', 'invitation_sent' => false]);
        $invited = $this->guest(['name' => 'Invited Person', 'invitation_sent' => true]);
        $seated = $this->accepted($this->guest(['name' => 'Seated Person']), 2);
        $declined = $this->guest(['name' => 'Declined Person']);
        Rsvp::factory()->forGuest($declined)->declined()->create();

        $this->assertSame('Remove Plain Person? This cannot be undone.', $plain->removalWarning());
        $this->assertStringContainsString('Their link and any pass already sent will stop working.', $invited->removalWarning());
        $this->assertStringContainsString('2 seats confirmed; they will be freed.', $seated->removalWarning());
        $this->assertStringContainsString('RSVP history is deleted too', $seated->removalWarning());
        $this->assertStringContainsString('Their answer will be deleted.', $declined->fresh(['rsvp'])->removalWarning());
        $this->assertStringNotContainsString('seat', $declined->fresh(['rsvp'])->removalWarning());
    }

    public function test_the_guest_list_puts_the_warning_on_the_remove_button(): void
    {
        $this->accepted($this->guest(['name' => 'Seated Person']), 2);

        $this->actingAs($this->host)
            ->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Remove Seated Person? They have 2 seats confirmed', false);
    }

    // ---------------------------------------------------------------- after a single removal

    public function test_removing_a_guest_reports_the_freed_seats_and_the_pool(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create(['name' => 'Committee', 'seat_limit' => 5, 'rsvp_token' => str_repeat('c', 48)]);
        $guest = $this->accepted($this->guest(['name' => 'Seated Person', 'guest_group_id' => $group->id]), 2);

        $this->remove($guest)
            ->assertRedirect()
            ->assertSessionHas('guest_removed', fn (array $r) => $r['name'] === 'Seated Person'
                && $r['seats_freed'] === 2
                && $r['pool'] === ['group' => 'Committee', 'left' => 5]);
    }

    public function test_the_page_shows_the_freed_seats_and_the_tell_them_button(): void
    {
        $guest = $this->accepted($this->guest(['name' => 'Seated Person', 'email' => 'seated@example.test']), 2);

        $response = $this->actingAs($this->host)
            ->followingRedirects()
            ->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $guest->id]));

        $response->assertSee('held 2 seats, now free again', false)
            ->assertSee('may not know they were removed', false)
            ->assertSee('Tell them');
    }

    public function test_no_tell_them_button_for_a_guest_with_no_email(): void
    {
        $guest = $this->accepted($this->guest(['name' => 'No Email', 'email' => null]));

        $this->remove($guest)->assertSessionHas('guest_removed', fn (array $r) => $r['tell_url'] === null);
    }

    public function test_no_tell_them_button_for_a_guest_who_was_never_invited_or_answered(): void
    {
        $guest = $this->guest(['email' => 'quiet@example.test', 'invitation_sent' => false]);

        $this->remove($guest)->assertSessionHas('guest_removed', fn (array $r) => $r['tell_url'] === null);
    }

    // ---------------------------------------------------------------- bulk and API

    public function test_a_bulk_removal_reports_the_freed_seats(): void
    {
        $a = $this->accepted($this->guest(), 2);
        $b = $this->accepted($this->guest(), 1);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->post(route('events.guests.bulk', $this->event), ['action' => 'delete', 'guest_ids' => [$a->id, $b->id]])
            ->assertSee('3 seats freed');
    }

    public function test_the_api_bulk_delete_reports_seats_freed_and_other_actions_report_null(): void
    {
        Sanctum::actingAs($this->host);
        $seated = $this->accepted($this->guest(), 2);
        $other = $this->guest();

        $this->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'mark_sent', 'guest_ids' => [$other->id]])
            ->assertOk()->assertJsonPath('seats_freed', null);
        $this->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'delete', 'guest_ids' => [$seated->id]])
            ->assertOk()->assertJsonPath('seats_freed', 2)->assertJsonPath('affected_count', 1);
    }

    // ---------------------------------------------------------------- the removed list

    public function test_the_removed_list_appears_only_when_someone_was_removed(): void
    {
        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertOk()->assertDontSee('Removed guests (');

        $guest = $this->accepted($this->guest(['name' => 'Gone Person', 'invitation_sent' => true]), 2);
        $this->remove($guest);

        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('Removed guests (1)', false)
            ->assertSee('Gone Person')
            ->assertSee('had 2 seats confirmed, was sent an invitation', false);
    }

    public function test_the_removed_list_is_capped(): void
    {
        for ($i = 0; $i < GuestRemoval::LIST_LIMIT + 5; $i++) {
            app(GuestDeleter::class)->delete($this->event, $this->guest(['name' => "Guest {$i}"]));
        }

        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertSee('Removed guests ('.GuestRemoval::LIST_LIMIT.')', false);
    }

    // ---------------------------------------------------------------- "Tell them"

    private function removedWithEmail(array $attributes = []): GuestRemoval
    {
        $guest = $this->accepted($this->guest($attributes + ['name' => 'Told Person', 'email' => 'told@example.test']));
        app(GuestDeleter::class)->delete($this->event, $guest);

        return GuestRemoval::query()->latest('id')->firstOrFail();
    }

    private function tell(GuestRemoval $removal, ?User $as = null, ?Event $event = null)
    {
        return $this->actingAs($as ?? $this->host)
            ->post(route('events.guests.removed.notify', ['event' => $event ?? $this->event, 'removal' => $removal]));
    }

    public function test_tell_them_sends_one_email_and_only_once(): void
    {
        $removal = $this->removedWithEmail();

        $this->tell($removal)->assertRedirect()->assertSessionHas('status', 'removed-guest-told');

        Notification::assertSentTo(
            new AnonymousNotifiable,
            GuestRemovedNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'told@example.test'
        );
        $this->assertNotNull($removal->fresh()->notified_at);

        $this->tell($removal)->assertSessionHasErrors('removed');
        Notification::assertSentTimes(GuestRemovedNotification::class, 1);
        $this->assertSame(1, NotificationLog::query()->where('type', 'guest_removed_notice')->count());
    }

    public function test_tell_them_is_refused_without_an_email_or_a_reason(): void
    {
        $noEmail = $this->removedWithEmail(['email' => null]);
        $this->tell($noEmail)->assertSessionHasErrors('removed');

        app(GuestDeleter::class)->delete($this->event, $this->guest(['email' => 'quiet@example.test', 'invitation_sent' => false]));
        $this->tell(GuestRemoval::query()->latest('id')->firstOrFail())->assertSessionHasErrors('removed');

        Notification::assertNothingSent();
    }

    public function test_only_the_owner_can_tell_them_and_only_for_their_own_event(): void
    {
        $removal = $this->removedWithEmail();
        $stranger = User::factory()->pro()->create();
        $otherEvent = Event::factory()->for($this->host)->published()->create(['is_public' => false]);

        $this->tell($removal, $stranger)->assertForbidden();
        $this->tell($removal, null, $otherEvent)->assertNotFound();

        Notification::assertNothingSent();
        $this->assertNull($removal->fresh()->notified_at);
    }

    public function test_the_list_shows_told_once_it_has_been_sent(): void
    {
        $removal = $this->removedWithEmail();

        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertSee('Tell them');

        $this->tell($removal);

        $this->actingAs($this->host)->get(route('events.guests.index', $this->event))
            ->assertSee('Told')
            ->assertDontSee('Tell them');
    }

    public function test_the_email_names_the_event_and_the_host_number_and_carries_no_link(): void
    {
        $mail = (new GuestRemovedNotification($this->event, 'Told Person'))->toMail(new AnonymousNotifiable);
        $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

        $this->assertStringContainsString($this->event->name, $text);
        $this->assertStringContainsString('0977000111', $text);
        $this->assertNull($mail->actionUrl);
    }
}
