<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-editing-gaps.md Phases 1 and 2: changing a guest's email or phone does not carry the old contact's reminder state,
 * "sent" flag or unsubscribe over to the new one, and the old address's access to the personal link is offered a one-click replacement.
 */
class GuestContactChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->host)->create();
    }

    private function sentGuest(array $overrides = []): Guest
    {
        return Guest::factory()->for($this->event)->create(array_merge([
            'name' => 'Sent Guest',
            'email' => 'old@example.test',
            'phone' => '0971234567',
            'invitation_sent' => true,
            'invitation_sent_at' => now()->subDay(),
            'rsvp_reminders_sent' => ['7'],
            'whatsapp_event_reminders_sent' => ['0'],
            'email_reminders_stopped_at' => now()->subHour(),
        ], $overrides));
    }

    private function edit(Guest $guest, array $changes = [])
    {
        return $this->actingAs($this->host)->patch(
            route('events.guests.update', ['event' => $this->event, 'guest' => $guest]),
            array_merge(['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone], $changes),
        );
    }

    private function log(Guest $guest, string $channel, string $key): NotificationLog
    {
        return NotificationLog::query()->create([
            'event_id' => $this->event->id,
            'guest_id' => $guest->id,
            'channel' => $channel,
            'type' => 'guest_event_reminder_'.$channel,
            'status' => NotificationLog::STATUS_SENT,
            'idempotency_key' => $key,
            'sent_at' => now(),
        ]);
    }

    // ── Phase 1: contact-change housekeeping ───────────────────────────────────────────────────────

    public function test_a_new_email_resets_the_email_reminder_state_and_the_sent_flag(): void
    {
        $guest = $this->sentGuest();

        $this->edit($guest, ['email' => 'new@example.test'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('guest_contact_reset', 'Sent Guest');

        $fresh = $guest->fresh();
        $this->assertSame('new@example.test', $fresh->email);
        $this->assertFalse($fresh->invitation_sent);
        $this->assertNull($fresh->invitation_sent_at);
        $this->assertSame([], $fresh->rsvp_reminders_sent);
        $this->assertNull($fresh->email_reminders_stopped_at, 'a stranger\'s unsubscribe does not follow the guest');
        $this->assertSame(['0'], $fresh->whatsapp_event_reminders_sent, 'the phone did not change');
    }

    public function test_a_new_phone_resets_the_whatsapp_state_and_the_sent_flag_but_not_the_email_state(): void
    {
        $guest = $this->sentGuest();

        $this->edit($guest, ['phone' => '0961112222'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('guest_contact_reset', 'Sent Guest');

        $fresh = $guest->fresh();
        $this->assertFalse($fresh->invitation_sent);
        $this->assertSame([], $fresh->whatsapp_event_reminders_sent);
        $this->assertSame(['7'], $fresh->rsvp_reminders_sent);
        $this->assertNotNull($fresh->email_reminders_stopped_at, 'the email did not change');
    }

    public function test_a_respelling_of_the_same_email_or_number_changes_nothing(): void
    {
        $guest = $this->sentGuest();

        $this->edit($guest, ['email' => '  OLD@Example.TEST ', 'phone' => '+260 97 123 4567'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('guest_contact_reset');

        $fresh = $guest->fresh();
        $this->assertTrue($fresh->invitation_sent);
        $this->assertSame(['7'], $fresh->rsvp_reminders_sent);
        $this->assertSame(['0'], $fresh->whatsapp_event_reminders_sent);
        $this->assertNotNull($fresh->email_reminders_stopped_at);
    }

    public function test_adding_or_clearing_a_contact_resets_nothing(): void
    {
        $noEmail = $this->sentGuest(['email' => null]);
        $this->edit($noEmail, ['email' => 'added@example.test'])->assertSessionMissing('guest_contact_reset');
        $this->assertTrue($noEmail->fresh()->invitation_sent);
        $this->assertSame(['7'], $noEmail->fresh()->rsvp_reminders_sent);

        $clear = $this->sentGuest(['email' => 'clear@example.test', 'phone' => '0962223333']);
        $this->edit($clear, ['email' => '', 'phone' => ''])->assertSessionMissing('guest_contact_reset');
        $this->assertTrue($clear->fresh()->invitation_sent);
        $this->assertSame(['7'], $clear->fresh()->rsvp_reminders_sent);
    }

    public function test_a_guest_who_was_never_sent_an_invitation_has_nothing_to_reset(): void
    {
        $guest = $this->sentGuest(['invitation_sent' => false, 'invitation_sent_at' => null]);

        $this->edit($guest, ['email' => 'new@example.test'])->assertSessionMissing('guest_contact_reset');

        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent, 'the reminder state is still cleared');
    }

    public function test_the_host_can_keep_it_marked_as_sent(): void
    {
        $guest = $this->sentGuest();

        $this->edit($guest, ['email' => 'new@example.test', 'keep_invitation_sent' => '1'])
            ->assertSessionMissing('guest_contact_reset');

        $fresh = $guest->fresh();
        $this->assertTrue($fresh->invitation_sent);
        $this->assertSame([], $fresh->rsvp_reminders_sent, 'only the sent flag is kept');
    }

    public function test_marking_sent_now_beats_the_reset(): void
    {
        $guest = $this->sentGuest(['invitation_sent' => false, 'invitation_sent_at' => null]);

        $this->edit($guest, ['email' => 'new@example.test', 'mark_invitation_sent' => '1'])
            ->assertSessionMissing('guest_contact_reset');

        $this->assertTrue($guest->fresh()->invitation_sent);
        $this->assertNotNull($guest->fresh()->invitation_sent_at);
    }

    public function test_a_sent_reminder_no_longer_blocks_a_reminder_to_the_new_address(): void
    {
        $guest = $this->sentGuest();
        $emailLog = $this->log($guest, 'email', 'rsvp-reminder:1:1:7:20261020');
        $otherEmail = $this->log($guest, 'email', 'rsvp-reminder:1:1:3:20261020');
        $whatsAppLog = $this->log($guest, 'whatsapp', 'wa-event-reminder:1:1:7d');
        $smsLog = $this->log($guest, 'sms', 'sms:1:1');

        $this->edit($guest, ['email' => 'new@example.test'])->assertSessionHasNoErrors();

        foreach ([$emailLog, $otherEmail] as $log) {
            $this->assertNull($log->fresh()->idempotency_key);
            $this->assertSame('contact_change', $log->fresh()->meta['superseded']);
            $this->assertSame(NotificationLog::STATUS_SENT, $log->fresh()->status, 'the record of what was sent stays');
        }
        $this->assertSame('wa-event-reminder:1:1:7d', $whatsAppLog->fresh()->idempotency_key, 'an email change leaves the phone channels alone');
        $this->assertSame('sms:1:1', $smsLog->fresh()->idempotency_key);

        $this->edit($guest->fresh(), ['phone' => '0961112222'])->assertSessionHasNoErrors();

        $this->assertNull($whatsAppLog->fresh()->idempotency_key);
        $this->assertNull($smsLog->fresh()->idempotency_key);
    }

    public function test_the_list_tells_the_host_the_invitation_is_no_longer_marked_sent(): void
    {
        $guest = $this->sentGuest();

        $this->actingAs($this->host)
            ->followingRedirects()
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'Sent Guest', 'email' => 'new@example.test', 'phone' => '0971234567'])
            ->assertSee('Marked not sent')
            ->assertSee('Sent Guest');
    }

    public function test_the_api_edit_follows_the_same_rules_and_reports_them(): void
    {
        $guest = $this->sentGuest();
        Sanctum::actingAs($this->host);

        $this->patchJson(route('api.v1.host.events.guests.update', ['event' => $this->event, 'guest' => $guest]), [
            'name' => 'Sent Guest', 'email' => 'new@example.test', 'phone' => '0971234567',
        ])
            ->assertOk()
            ->assertJsonPath('email', 'new@example.test')
            ->assertJsonPath('invitation_reset', true)
            ->assertJsonPath('link_replaced', false);

        $this->assertFalse($guest->fresh()->invitation_sent);
        $this->assertSame([], $guest->fresh()->rsvp_reminders_sent);
    }

    public function test_an_edit_that_changes_no_contact_still_saves_everything_else(): void
    {
        $guest = $this->sentGuest();

        $this->edit($guest, ['name' => 'Renamed Guest', 'plus_one_allowed' => '1'])->assertSessionHasNoErrors();

        $fresh = $guest->fresh();
        $this->assertSame('Renamed Guest', $fresh->name);
        $this->assertTrue($fresh->plus_one_allowed);
        $this->assertTrue($fresh->invitation_sent);
    }

    // ── Phase 2: the old link ──────────────────────────────────────────────────────────────────────

    public function test_an_email_change_without_a_new_link_offers_to_replace_it(): void
    {
        $guest = $this->sentGuest();

        $this->edit($guest, ['email' => 'new@example.test'])
            ->assertSessionHas('old_link_still_works', fn (array $f) => $f['guest'] === 'Sent Guest'
                && $f['url'] === route('events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]));
    }

    public function test_a_guest_who_has_answered_is_protected_even_if_never_marked_sent(): void
    {
        $guest = $this->sentGuest(['invitation_sent' => false, 'invitation_sent_at' => null]);
        Rsvp::factory()->for($guest)->create(['status' => RsvpStatus::Accepted, 'attendee_count' => 1]);

        $this->edit($guest, ['email' => 'new@example.test'])->assertSessionHas('old_link_still_works');
    }

    public function test_a_guest_with_no_history_is_not_nagged(): void
    {
        $guest = $this->sentGuest(['invitation_sent' => false, 'invitation_sent_at' => null]);

        $this->edit($guest, ['email' => 'new@example.test'])->assertSessionMissing('old_link_still_works');
    }

    public function test_clearing_the_email_also_offers_it_but_adding_one_does_not(): void
    {
        $guest = $this->sentGuest();
        $this->edit($guest, ['email' => ''])->assertSessionHas('old_link_still_works');

        $noEmail = $this->sentGuest(['email' => null, 'phone' => '0962223333']);
        $this->edit($noEmail, ['email' => 'added@example.test', 'phone' => '0962223333'])->assertSessionMissing('old_link_still_works');
    }

    public function test_replacing_the_link_with_the_edit_kills_the_old_link_and_shows_the_new_one(): void
    {
        $guest = $this->sentGuest(['invitation_token' => 'old-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
        $oldQr = $guest->checkInQrUrl();

        $this->edit($guest, ['email' => 'new@example.test', 'regenerate_invitation_token' => '1'])
            ->assertSessionMissing('old_link_still_works')
            ->assertSessionHas('guest_link_replaced', fn (array $f) => $f['guest'] === 'Sent Guest' && str_contains($f['link'], $guest->fresh()->invitation_token));

        $fresh = $guest->fresh();
        $this->assertNotSame('old-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $fresh->invitation_token);
        $this->assertNotSame($oldQr, $fresh->checkInQrUrl(), 'the QR on a pass already sent is a different code now');
        $this->get(route('rsvp.token.show', ['token' => 'old-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']))->assertNotFound();
        $this->get(route('rsvp.token.show', ['token' => $fresh->invitation_token]))->assertOk();
    }

    public function test_the_one_click_replacement_works_for_the_owner_only(): void
    {
        $guest = $this->sentGuest(['invitation_token' => 'old-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);

        $this->actingAs(User::factory()->create())
            ->post(route('events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]))
            ->assertForbidden();
        $this->assertSame('old-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $guest->fresh()->invitation_token);

        $this->actingAs($this->host)
            ->post(route('events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]))
            ->assertRedirect(route('events.guests.index', $this->event))
            ->assertSessionHas('guest_link_replaced');

        $this->assertNotSame('old-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $guest->fresh()->invitation_token);
    }

    public function test_the_list_shows_the_replace_button_and_the_new_link(): void
    {
        $guest = $this->sentGuest();

        $this->actingAs($this->host)
            ->followingRedirects()
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'Sent Guest', 'email' => 'new@example.test', 'phone' => '0971234567'])
            ->assertSee('old email address can still open their link')
            ->assertSee('Replace link now');

        $this->actingAs($this->host)
            ->followingRedirects()
            ->post(route('events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]))
            ->assertSee('New link for')
            ->assertSee($guest->fresh()->invitation_token);
    }

    public function test_the_edit_page_warns_about_the_qr_and_marks_guests_with_history(): void
    {
        $withHistory = $this->sentGuest();
        $fresh = Guest::factory()->for($this->event)->create(['invitation_sent' => false]);

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $withHistory]))
            ->assertOk()
            ->assertSee('data-has-history="1"', false)
            ->assertSee('the door scans the same code')
            ->assertSee('Keep it marked as sent if I change their email or phone');

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $fresh]))
            ->assertSee('data-has-history="0"', false);
    }

    public function test_the_api_replaces_a_link(): void
    {
        $guest = $this->sentGuest(['invitation_token' => 'old-token-cccccccccccccccccccccccccccccccccccccccc']);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson(route('api.v1.host.events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]))->assertForbidden();

        Sanctum::actingAs($this->host);
        $response = $this->postJson(route('api.v1.host.events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]))->assertOk();

        $this->assertSame($guest->fresh()->personalRsvpUrl(), $response->json('personal_rsvp_url'));
        $this->assertStringNotContainsString('old-token-cccc', $response->json('personal_rsvp_url'));
    }
}
