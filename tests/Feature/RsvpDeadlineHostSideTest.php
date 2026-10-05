<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use App\Services\CommunicationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-deadline-fixes.md, Phase 4: what the host sees and can do. A deadline already in the past is
 * rejected (G8), a save that reopens RSVP offers a reminder prompt (D7), and invitations and reminders are
 * not sent into a closed form (G10, D8). Times are venue wall-clock (Africa/Lusaka).
 */
class RsvpDeadlineHostSideTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = User::factory()->proPlus()->create();
        $this->at('2026-10-10 12:00:00');

        $this->event = Event::factory()->for($this->owner)->published()->create([
            'is_public' => true,
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-20 18:00:00',
        ]);
    }

    private function at(string $venueTime): void
    {
        Carbon::setTestNow(Carbon::parse($venueTime, config('events.timezone'))->utc());
    }

    private function closeRsvp(): void
    {
        $this->at('2026-10-21 09:00:00');
    }

    /** @return array<string, mixed> */
    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Summer Gathering',
            'event_type' => 'birthday',
            'audience' => 'private',
            'product_kind' => 'invitation',
            'host_contact_phone' => '0977123456',
            'event_date' => '2026-11-20',
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
            'allow_plus_one' => '0',
            'show_guest_list' => '0',
        ], $overrides);
    }

    private function update(array $fields)
    {
        return $this->actingAs($this->owner)->patch(route('events.update', $this->event), array_merge(['name' => $this->event->name], $fields));
    }

    // ── G8: a deadline already in the past ───────────────────────────────────

    public function test_a_new_event_with_a_deadline_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('events.store'), $this->storePayload(['rsvp_deadline' => '2026-10-09T10:00']))
            ->assertSessionHasErrors('rsvp_deadline');

        $this->assertSame(1, Event::query()->count(), 'only the event from setUp');
    }

    public function test_a_deadline_set_to_about_now_is_allowed_thanks_to_the_slack(): void
    {
        $this->actingAs($this->owner)
            ->post(route('events.store'), $this->storePayload(['rsvp_deadline' => '2026-10-10T11:57']))
            ->assertSessionHasNoErrors();
    }

    public function test_a_future_or_blank_deadline_is_allowed_on_create(): void
    {
        $this->actingAs($this->owner)->post(route('events.store'), $this->storePayload(['rsvp_deadline' => '2026-10-20T18:00']))->assertSessionHasNoErrors();
        $this->post(route('events.store'), $this->storePayload(['name' => 'No deadline', 'rsvp_deadline' => '']))->assertSessionHasNoErrors();
    }

    public function test_the_deadline_is_judged_on_the_venue_clock_not_utc(): void
    {
        // 13:30 Lusaka is 11:30 UTC. A deadline typed as 12:00 is 30 minutes away for the host, so it is fine
        // on the venue clock; judged as UTC it would already be in the past.
        $this->at('2026-10-10 13:30:00');

        $this->actingAs($this->owner)
            ->post(route('events.store'), $this->storePayload(['rsvp_deadline' => '2026-10-10T14:00']))
            ->assertSessionHasNoErrors();
    }

    public function test_updating_to_a_past_deadline_is_rejected_but_an_untouched_one_is_not(): void
    {
        $this->closeRsvp(); // the stored 20 Oct deadline is now behind us

        $this->update(['rsvp_deadline' => '2026-10-15T10:00'])->assertSessionHasErrors('rsvp_deadline');
        $this->assertSame('2026-10-20 18:00:00', $this->event->fresh()->rsvp_deadline->format('Y-m-d H:i:s'));

        // Same stored value, other edits: nothing to complain about.
        $this->update(['name' => 'Renamed', 'rsvp_deadline' => '2026-10-20T18:00'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $this->event->fresh()->name);
    }

    public function test_a_save_without_the_deadline_field_is_never_blocked_by_it(): void
    {
        $this->closeRsvp();

        $this->actingAs($this->owner)->patch(route('events.update', $this->event), ['name' => 'Only the name'])->assertSessionHasNoErrors();
    }

    public function test_the_deadline_can_be_extended_or_removed_after_it_passed(): void
    {
        $this->closeRsvp();

        $this->update(['rsvp_deadline' => '2026-10-30T18:00'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-30 18:00:00', $this->event->fresh()->rsvp_deadline->format('Y-m-d H:i:s'));

        $this->update(['rsvp_deadline' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->event->fresh()->rsvp_deadline);
    }

    public function test_the_mobile_api_shares_the_same_rule(): void
    {
        $this->closeRsvp();
        $token = $this->owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(route('api.v1.host.events.update', $this->event), ['rsvp_deadline' => '2026-10-15T10:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rsvp_deadline');
    }

    // ── D7: a save that reopens RSVP offers a reminder prompt ────────────────

    public function test_extending_a_passed_deadline_flashes_the_reopened_prompt(): void
    {
        $this->closeRsvp();

        $this->update(['rsvp_deadline' => '2026-10-30T18:00'])
            ->assertSessionHas('rsvp_reopened', route('events.guests.index', $this->event));

        $this->get(route('events.index'))
            ->assertOk()
            ->assertSee('RSVP is open again')
            ->assertSee(route('events.guests.index', $this->event), false);
    }

    public function test_removing_a_passed_deadline_reopens_rsvp_and_flashes_the_prompt(): void
    {
        $this->closeRsvp();
        $this->update(['rsvp_deadline' => ''])->assertSessionHas('rsvp_reopened');
    }

    public function test_moving_a_started_event_to_a_later_date_reopens_rsvp(): void
    {
        // No deadline, so RSVP closed when the event started on 21 Oct; moving the date out reopens it.
        $event = Event::factory()->for($this->owner)->published()->create(['event_date' => '2026-10-21', 'event_time' => '08:00:00', 'rsvp_deadline' => null]);
        $this->at('2026-10-21 12:00:00');
        $this->assertFalse($event->fresh()->isRsvpOpen());

        $this->actingAs($this->owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'event_date' => '2026-11-05', 'event_time' => '15:00'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('rsvp_reopened');
    }

    public function test_no_prompt_when_the_rsvp_was_already_open_or_stays_closed(): void
    {
        // Already open: an ordinary edit.
        $this->at('2026-10-12 09:00:00');
        $this->update(['rsvp_deadline' => '2026-10-25T18:00'])->assertSessionMissing('rsvp_reopened');

        // Stays closed: a cancelled event does not reopen just because its deadline is extended.
        $this->closeRsvp();
        $this->event->forceFill(['cancelled_at' => now()])->save();
        $this->update(['rsvp_deadline' => '2026-10-30T18:00'])->assertSessionMissing('rsvp_reopened');
    }

    // ── G10: not sending into a closed form ──────────────────────────────────

    public function test_the_whatsapp_invitation_is_refused_while_rsvp_is_closed(): void
    {
        config(['communications.whatsapp.enabled' => true]);
        $guest = Guest::factory()->for($this->event)->create(['phone' => '+260971234567']);
        $this->closeRsvp();

        $this->assertSame('closed', app(CommunicationService::class)->sendWhatsAppInvitation($this->event->fresh(), $guest));

        $this->actingAs($this->owner)
            ->post(route('events.guests.whatsapp-invite', [$this->event, $guest]))
            ->assertSessionHas('status', 'guest-whatsapp-closed');
    }

    public function test_the_api_whatsapp_invitation_reports_closed(): void
    {
        config(['communications.whatsapp.enabled' => true]);
        $guest = Guest::factory()->for($this->event)->create(['phone' => '+260971234567']);
        $this->closeRsvp();
        $token = $this->owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.v1.host.events.guests.whatsapp-invite', [$this->event, $guest]))
            ->assertOk()
            ->assertJsonPath('outcome', 'closed');
    }

    public function test_bulk_reminders_and_whatsapp_shares_are_refused_while_closed_but_bookkeeping_is_not(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['email' => 'g@example.test']);
        $this->closeRsvp();
        $this->actingAs($this->owner);

        foreach (['send_reminder_email', 'prepare_whatsapp_share'] as $action) {
            $this->post(route('events.guests.bulk', $this->event), ['action' => $action, 'guest_ids' => [$guest->id], 'days_until' => 3])
                ->assertSessionHasErrors('action');
        }
        $this->assertFalse((bool) $guest->fresh()->invitation_sent);

        $this->post(route('events.guests.bulk', $this->event), ['action' => 'mark_sent', 'guest_ids' => [$guest->id]])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $guest->fresh()->invitation_sent);
    }

    public function test_the_api_bulk_actions_are_refused_while_closed(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['email' => 'g@example.test']);
        $this->closeRsvp();
        $token = $this->owner->createToken('test')->plainTextToken;

        foreach (['send_reminder_email', 'prepare_whatsapp_share'] as $action) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => $action, 'guest_ids' => [$guest->id], 'days_until' => 3])
                ->assertStatus(422)
                ->assertJsonPath('error', 'rsvp_closed');
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'mark_sent', 'guest_ids' => [$guest->id]])
            ->assertOk();
    }

    public function test_sending_works_again_once_the_deadline_is_extended(): void
    {
        $guest = Guest::factory()->for($this->event)->create(['email' => 'g@example.test']);
        $this->closeRsvp();
        $this->update(['rsvp_deadline' => '2026-10-30T18:00'])->assertSessionHasNoErrors();

        $this->post(route('events.guests.bulk', $this->event), ['action' => 'prepare_whatsapp_share', 'guest_ids' => [$guest->id]])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $guest->fresh()->invitation_sent);
    }

    // ── The banner and the reasons ───────────────────────────────────────────

    public function test_the_banner_shows_on_the_guest_list_and_event_page_while_closed_and_not_otherwise(): void
    {
        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))->assertOk()->assertDontSee('RSVP is closed.');

        $this->closeRsvp();

        foreach ([route('events.guests.index', $this->event), route('events.show', $this->event)] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('RSVP is closed.')
                ->assertSee('The RSVP deadline passed on Tuesday, October 20, 2026 at 6:00 PM CAT.')
                ->assertSee('Extend the deadline')
                ->assertSee('invitations and reminders are paused');
        }
    }

    public function test_the_banner_is_absent_for_a_draft(): void
    {
        $this->event->forceFill(['is_published' => false])->save();
        $this->closeRsvp();

        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))->assertOk()->assertDontSee('RSVP is closed.');
    }

    public function test_the_banner_offers_no_extension_once_the_event_has_taken_place(): void
    {
        $this->at('2026-11-25 09:00:00');

        $this->actingAs($this->owner)->get(route('events.guests.index', $this->event))
            ->assertOk()
            ->assertSee('This event has already taken place.')
            ->assertDontSee('Extend the deadline');
    }

    public function test_the_closed_reasons(): void
    {
        $this->assertNull($this->event->rsvpClosedReason());

        $this->closeRsvp();
        $this->assertSame('The RSVP deadline passed on Tuesday, October 20, 2026 at 6:00 PM CAT.', $this->event->rsvpClosedReason());

        $noDeadline = Event::factory()->for($this->owner)->published()->create(['event_date' => '2026-10-21', 'event_time' => '08:00:00', 'rsvp_deadline' => null]);
        $this->assertSame('RSVP closed when the event started.', $noDeadline->rsvpClosedReason());

        $this->assertSame('This event is cancelled.', Event::factory()->make(['cancelled_at' => now(), 'event_date' => '2026-12-01'])->rsvpClosedReason());
        $this->assertSame('The invitation is paused.', Event::factory()->make(['invitation_paused_at' => now(), 'event_date' => '2026-12-01'])->rsvpClosedReason());
    }
}
