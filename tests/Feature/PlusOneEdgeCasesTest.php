<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\PlusOneRemovedNotification;
use App\Services\RsvpSubmissionService;
use App\Support\EventAttendance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan: plans/plus-one-edge-cases.md. Phase 3 — every channel applies the same seat rule and a
 * count outside it is refused, never silently stored as something else.
 */
class PlusOneEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function guestFor(bool $eventAllows, bool $guestAllows): Guest
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'allow_plus_one' => $eventAllows,
            'rsvp_deadline' => null,
        ]);

        return Guest::factory()->for($event)->create(['plus_one_allowed' => $guestAllows]);
    }

    public function test_service_refuses_more_seats_than_allowed_instead_of_clamping(): void
    {
        $guest = $this->guestFor(eventAllows: false, guestAllows: false);

        try {
            app(RsvpSubmissionService::class)->submit($guest->event, $guest, [
                'status' => RsvpStatus::Accepted,
                'attendee_count' => 5,
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('attendee_count', $e->errors());
        }

        $this->assertDatabaseCount('rsvps', 0);
    }

    public function test_service_refuses_zero_seats_for_an_accept(): void
    {
        $guest = $this->guestFor(eventAllows: true, guestAllows: true);

        $this->expectException(ValidationException::class);

        app(RsvpSubmissionService::class)->submit($guest->event, $guest, [
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 0,
        ]);
    }

    public function test_held_plus_one_survives_an_unchanged_resubmit_after_plus_ones_are_off(): void
    {
        $guest = $this->guestFor(eventAllows: false, guestAllows: false);
        Rsvp::factory()->forGuest($guest)->accepted(2)->create();

        $rsvp = app(RsvpSubmissionService::class)->submit($guest->event, $guest, [
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 2,
        ]);

        $this->assertSame(2, $rsvp->attendee_count);
    }

    public function test_held_plus_one_cannot_grow_past_what_was_held(): void
    {
        $guest = $this->guestFor(eventAllows: false, guestAllows: false);
        Rsvp::factory()->forGuest($guest)->accepted(2)->create();

        $this->expectException(ValidationException::class);

        app(RsvpSubmissionService::class)->submit($guest->event, $guest, [
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 3,
        ]);
    }

    // ── Phase 1: switching plus-ones off after some were confirmed ───────────

    /** @return array{0: User, 1: Event, 2: Guest} */
    private function hostedEventWithPlusOne(): array
    {
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'allow_plus_one' => true,
            'rsvp_deadline' => null,
        ]);
        $guest = Guest::factory()->for($event)->create([
            'plus_one_allowed' => true,
            'email' => 'guest@example.com',
            'invitation_token' => 'tok_plus_one',
        ]);
        Rsvp::factory()->forGuest($guest)->accepted(2)->create();

        return [$owner, $event, $guest];
    }

    public function test_switching_plus_ones_off_keeps_confirmed_ones_and_tells_the_host(): void
    {
        [$owner, $event, $guest] = $this->hostedEventWithPlusOne();

        $this->actingAs($owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'allow_plus_one' => '0'])
            ->assertSessionHas('plus_ones_kept', fn (array $v) => $v['count'] === 1);

        $this->assertFalse($event->fresh()->allow_plus_one);
        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_no_notice_when_nobody_has_a_plus_one(): void
    {
        [$owner, $event, $guest] = $this->hostedEventWithPlusOne();
        $guest->rsvp->update(['attendee_count' => 1]);

        $this->actingAs($owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'allow_plus_one' => '0'])
            ->assertSessionMissing('plus_ones_kept');
    }

    public function test_no_notice_when_plus_ones_stay_on(): void
    {
        [$owner, $event] = $this->hostedEventWithPlusOne();

        $this->actingAs($owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'allow_plus_one' => '1'])
            ->assertSessionMissing('plus_ones_kept');
    }

    public function test_api_reports_how_many_plus_ones_remain(): void
    {
        [$owner, $event] = $this->hostedEventWithPlusOne();
        Sanctum::actingAs($owner);

        $this->patchJson(route('api.v1.host.events.update', $event), ['name' => $event->name, 'allow_plus_one' => false])
            ->assertOk()
            ->assertJsonPath('plus_ones_remaining', 1);
    }

    public function test_guest_resubmits_unchanged_after_plus_ones_are_switched_off(): void
    {
        Notification::fake();
        [, $event, $guest] = $this->hostedEventWithPlusOne();
        $event->update(['allow_plus_one' => false]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_plus_one']), [
            'status' => 'accepted',
            'attendee_count' => 2,
            'message' => 'Still coming',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_guest_cannot_use_a_plus_one_they_do_not_hold_when_off(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'allow_plus_one' => false,
            'rsvp_deadline' => null,
        ]);
        Guest::factory()->for($event)->create(['plus_one_allowed' => true, 'invitation_token' => 'tok_new']);

        $this->post(route('rsvp.token.store', ['token' => 'tok_new']), [
            'status' => 'accepted',
            'attendee_count' => 2,
        ])->assertSessionHasErrors('attendee_count');
    }

    public function test_switching_a_guest_off_keeps_their_confirmed_plus_one(): void
    {
        [$owner, $event, $guest] = $this->hostedEventWithPlusOne();

        $this->actingAs($owner)
            ->patch(route('events.guests.update', ['event' => $event, 'guest' => $guest->id]), [
                'name' => $guest->name,
                'plus_one_allowed' => '0',
            ])
            ->assertSessionHas('plus_one_kept', $guest->name);

        $this->assertFalse($guest->fresh()->plus_one_allowed);
        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_host_can_remove_a_confirmed_plus_one_and_the_guest_is_emailed(): void
    {
        Notification::fake();
        [$owner, $event, $guest] = $this->hostedEventWithPlusOne();

        $this->actingAs($owner)
            ->patch(route('events.guests.rsvp.remove-plus-one', ['event' => $event, 'guest' => $guest->id]))
            ->assertSessionHas('status', 'guest-plus-one-removed');

        $this->assertSame(1, $guest->fresh()->rsvp->attendee_count);
        Notification::assertSentOnDemand(PlusOneRemovedNotification::class);

        // Nothing left to remove the second time.
        $this->actingAs($owner)
            ->patch(route('events.guests.rsvp.remove-plus-one', ['event' => $event, 'guest' => $guest->id]))
            ->assertSessionHasErrors('plus_one');
    }

    public function test_removing_a_plus_one_frees_the_seat_for_the_guest_limit(): void
    {
        Notification::fake();
        [$owner, $event, $guest] = $this->hostedEventWithPlusOne();
        $event->update(['guest_limit' => 2]);

        $this->actingAs($owner)
            ->patch(route('events.guests.rsvp.remove-plus-one', ['event' => $event, 'guest' => $guest->id]));

        $this->assertSame(1, EventAttendance::heldSeats($event->id));
    }

    public function test_only_the_owner_can_remove_a_plus_one(): void
    {
        [, $event, $guest] = $this->hostedEventWithPlusOne();

        $this->actingAs(User::factory()->create())
            ->patch(route('events.guests.rsvp.remove-plus-one', ['event' => $event, 'guest' => $guest->id]))
            ->assertForbidden();

        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    // ── Phase 2: switching plus-ones on after guests exist ───────────────────

    private function hostWithGuestsAndPlusOnesOff(): array
    {
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->published()->create([
            'allow_plus_one' => false,
            'rsvp_deadline' => null,
        ]);
        $guests = Guest::factory()->count(3)->for($event)->create(['plus_one_allowed' => false]);

        return [$owner, $event, $guests];
    }

    public function test_switching_plus_ones_on_offers_to_allow_them_for_guests_who_cannot_use_them(): void
    {
        [$owner, $event] = $this->hostWithGuestsAndPlusOnesOff();

        $this->actingAs($owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'allow_plus_one' => '1'])
            ->assertSessionHas('plus_ones_available', fn (array $v) => $v['count'] === 3
                && $v['url'] === route('events.guests.allow-plus-one', $event));
    }

    public function test_no_offer_when_every_guest_already_has_it_or_the_toggle_was_already_on(): void
    {
        [$owner, $event] = $this->hostWithGuestsAndPlusOnesOff();
        $event->guests()->update(['plus_one_allowed' => true]);

        $this->actingAs($owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'allow_plus_one' => '1'])
            ->assertSessionMissing('plus_ones_available');

        $event->guests()->update(['plus_one_allowed' => false]);
        $event->update(['allow_plus_one' => true]);

        $this->actingAs($owner)
            ->patch(route('events.update', $event), ['name' => $event->name, 'allow_plus_one' => '1'])
            ->assertSessionMissing('plus_ones_available');
    }

    public function test_api_reports_guests_who_cannot_use_plus_ones_yet(): void
    {
        [$owner, $event] = $this->hostWithGuestsAndPlusOnesOff();
        Sanctum::actingAs($owner);

        $this->patchJson(route('api.v1.host.events.update', $event), ['name' => $event->name, 'allow_plus_one' => true])
            ->assertOk()
            ->assertJsonPath('guests_without_plus_one', 3);
    }

    public function test_one_click_allows_plus_ones_for_every_guest_of_that_event_only(): void
    {
        [$owner, $event] = $this->hostWithGuestsAndPlusOnesOff();
        $other = Event::factory()->for($owner)->published()->create();
        $foreign = Guest::factory()->for($other)->create(['plus_one_allowed' => false]);

        $this->actingAs($owner)
            ->post(route('events.guests.allow-plus-one', $event))
            ->assertRedirect(route('events.guests.index', $event))
            ->assertSessionHas('bulk_count', 3);

        $this->assertSame(0, $event->guestsWithoutPlusOne());
        $this->assertFalse($foreign->fresh()->plus_one_allowed);
    }

    public function test_one_click_leaves_confirmed_seats_alone_and_is_owner_only(): void
    {
        [$owner, $event, $guests] = $this->hostWithGuestsAndPlusOnesOff();
        Rsvp::factory()->forGuest($guests->first())->accepted(1)->create();

        $this->actingAs(User::factory()->create())
            ->post(route('events.guests.allow-plus-one', $event))
            ->assertForbidden();

        $this->actingAs($owner)->post(route('events.guests.allow-plus-one', $event));

        $this->assertSame(1, $guests->first()->fresh()->rsvp->attendee_count);
    }

    public function test_bulk_action_allows_plus_ones_for_the_selected_guests_only(): void
    {
        [$owner, $event, $guests] = $this->hostWithGuestsAndPlusOnesOff();
        $picked = $guests->take(2);

        $this->actingAs($owner)
            ->post(route('events.guests.bulk', $event), [
                'action' => 'allow_plus_one',
                'guest_ids' => $picked->pluck('id')->all(),
            ])
            ->assertSessionHas('status', 'guests-bulk-plus-one')
            ->assertSessionHas('bulk_count', 2);

        $this->assertSame(1, $event->guestsWithoutPlusOne());
    }

    public function test_api_bulk_action_allows_plus_ones(): void
    {
        [$owner, $event, $guests] = $this->hostWithGuestsAndPlusOnesOff();
        Sanctum::actingAs($owner);

        $this->postJson(route('api.v1.host.events.guests.bulk', $event), [
            'action' => 'allow_plus_one',
            'guest_ids' => $guests->pluck('id')->all(),
        ])->assertOk()->assertJson(['action' => 'allow_plus_one', 'affected_count' => 3]);
    }

    public function test_guest_can_pick_a_plus_one_once_allowed(): void
    {
        Notification::fake();
        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'allow_plus_one' => true,
            'rsvp_deadline' => null,
        ]);
        $guest = Guest::factory()->for($event)->create(['plus_one_allowed' => false, 'invitation_token' => 'tok_late']);
        $payload = ['status' => 'accepted', 'attendee_count' => 2];

        $this->post(route('rsvp.token.store', ['token' => 'tok_late']), $payload)->assertSessionHasErrors('attendee_count');

        $guest->update(['plus_one_allowed' => true]);

        $this->post(route('rsvp.token.store', ['token' => 'tok_late']), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_import_reads_an_optional_plus_one_column(): void
    {
        [$owner, $event] = $this->hostWithGuestsAndPlusOnesOff();
        $csv = "name,email,phone,group,table,plus_one\nYes Guest,y@example.test,,,,yes\nNo Guest,n@example.test,,,,\nBlank Col,b@example.test,,,,no\n";

        Sanctum::actingAs($owner);
        $file = UploadedFile::fake()->createWithContent('guests.csv', $csv);

        $this->postJson(route('api.v1.host.events.guests.import.store', $event), ['file' => $file])->assertSuccessful();

        $this->assertTrue($event->guests()->where('email', 'y@example.test')->first()->plus_one_allowed);
        $this->assertFalse($event->guests()->where('email', 'n@example.test')->first()->plus_one_allowed);
        $this->assertFalse($event->guests()->where('email', 'b@example.test')->first()->plus_one_allowed);
    }

    // ── Phase 5: a plus-one that does not fit the guest limit ────────────────

    private function fullishEvent(int $limit, int $takenByOthers): array
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'allow_plus_one' => true,
            'guest_limit' => $limit,
            'rsvp_deadline' => null,
        ]);
        $other = Guest::factory()->for($event)->create(['plus_one_allowed' => true]);
        Rsvp::factory()->forGuest($other)->accepted($takenByOthers)->create();
        $guest = Guest::factory()->for($event)->create(['plus_one_allowed' => true, 'invitation_token' => 'tok_fit']);

        return [$event, $guest];
    }

    public function test_one_seat_left_tells_the_guest_they_can_rsvp_alone_and_preselects_it(): void
    {
        Notification::fake();
        $this->fullishEvent(limit: 3, takenByOthers: 2);

        $this->from('/back')
            ->post(route('rsvp.token.store', ['token' => 'tok_fit']), ['status' => 'accepted', 'attendee_count' => 2])
            ->assertRedirect('/back')
            ->assertSessionHasErrors(['status' => 'Only 1 seat is left for confirmed attendees. You can RSVP for yourself only.'])
            ->assertSessionHasInput('attendee_count', 1);
    }

    public function test_no_seats_left_keeps_the_original_message(): void
    {
        Notification::fake();
        $this->fullishEvent(limit: 2, takenByOthers: 2);

        $this->post(route('rsvp.token.store', ['token' => 'tok_fit']), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertSessionHasErrors(['status' => 'This event has reached its guest limit for confirmed attendees.']);
    }

    public function test_guest_who_already_holds_a_seat_is_told_it_is_unchanged(): void
    {
        Notification::fake();
        [$event, $guest] = $this->fullishEvent(limit: 3, takenByOthers: 1);
        Rsvp::factory()->forGuest($guest)->accepted(1)->create();
        Rsvp::factory()->forGuest(Guest::factory()->for($event)->create())->accepted(1)->create();

        $this->post(route('rsvp.token.store', ['token' => 'tok_fit']), ['status' => 'accepted', 'attendee_count' => 2])
            ->assertSessionHasErrors(['status' => 'Only 1 seat is left for confirmed attendees. You can RSVP for yourself only. Your current RSVP is unchanged.']);

        $this->assertSame(1, $guest->fresh()->rsvp->attendee_count);
    }

    public function test_api_422_reports_seats_left_and_keeps_the_error_shape(): void
    {
        Notification::fake();
        $this->fullishEvent(limit: 3, takenByOthers: 2);

        $this->postJson('/api/v1/rsvp/tok_fit', ['status' => 'accepted', 'attendee_count' => 2])
            ->assertStatus(422)
            ->assertJsonPath('seats_left', 1)
            ->assertJsonValidationErrors('status');
    }

    public function test_no_other_path_creates_an_rsvp_outside_the_submission_service(): void
    {
        $offenders = collect(File::allFiles(app_path()))
            ->reject(fn ($f) => $f->getFilename() === 'RsvpSubmissionService.php')
            ->filter(fn ($f) => preg_match('/Rsvp::(query\(\)->)?(create|updateOrCreate|firstOrCreate|insert)|rsvp\(\)->(create|updateOrCreate)/', $f->getContents()))
            ->map(fn ($f) => $f->getRelativePathname());

        $this->assertSame([], $offenders->values()->all(), 'RSVPs must go through RsvpSubmissionService so the guest limit holds.');
    }

    // ── Phase 4: wording ─────────────────────────────────────────────────────

    public function test_rsvp_form_says_just_me_and_me_plus_one(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'allow_plus_one' => true,
            'rsvp_deadline' => null,
        ]);
        Guest::factory()->for($event)->create(['plus_one_allowed' => true, 'invitation_token' => 'tok_words']);

        $this->get(route('rsvp.token.show', ['token' => 'tok_words']))
            ->assertOk()
            ->assertSeeInOrder(['Not attending', 'Just me', 'Me + 1 guest'])
            ->assertDontSee('(not attending)');
    }

    public function test_rsvp_form_offers_no_plus_one_wording_when_not_allowed(): void
    {
        $event = Event::factory()->for(User::factory()->create())->published()->create([
            'allow_plus_one' => true,
            'rsvp_deadline' => null,
        ]);
        Guest::factory()->for($event)->create(['plus_one_allowed' => false, 'invitation_token' => 'tok_solo']);

        $this->get(route('rsvp.token.show', ['token' => 'tok_solo']))
            ->assertOk()
            ->assertSee('Just me')
            ->assertDontSee('Me + 1 guest');
    }

    public function test_api_open_rsvp_rejects_an_over_max_count(): void
    {
        $event = Event::factory()->for(User::factory()->create())->publicAudience()->published()->create([
            'allow_plus_one' => true,
            'rsvp_deadline' => null,
        ]);

        $this->postJson("/api/v1/events/{$event->slug}/rsvp", [
            'name' => 'Open Guest',
            'email' => 'open@example.com',
            'status' => 'accepted',
            'attendee_count' => 5,
        ])->assertStatus(422);

        $this->assertDatabaseCount('rsvps', 0);
    }
}
