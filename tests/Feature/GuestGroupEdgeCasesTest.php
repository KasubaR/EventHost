<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Models\Event;
use App\Models\GroupLinkRemoval;
use App\Models\Guest;
use App\Models\GuestEdit;
use App\Models\GuestGroup;
use App\Models\User;
use App\Services\GuestGroupDeleter;
use App\Services\GuestGroupNamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-groups-gaps.md, Phases 0 and 1: what groups do when empty, duplicated, deleted with guests in them, or reached
 * from another event, and that deleting a group or closing its link never releases a link joiner from review.
 */
class GuestGroupEdgeCasesTest extends TestCase
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
            'is_public' => false,
            'rsvp_deadline' => null,
            'require_rsvp_approval' => false,
            'allow_plus_one' => true,
            'host_contact_phone' => '0977123456',
        ]);
    }

    private function group(string $name = 'Committee', ?Event $event = null, ?int $seats = 10): GuestGroup
    {
        $group = GuestGroup::factory()->for($event ?? $this->event)->create(['name' => $name]);
        if ($seats !== null) {
            $group->enableLink($seats);
        }

        return $group->fresh();
    }

    /** A real sign-up through the group's shared link: the guest is held for review and flagged as a joiner. */
    private function join(GuestGroup $group, string $suffix = '1'): Guest
    {
        $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), [
            'name' => 'Joiner '.$suffix,
            'email' => "joiner{$suffix}@example.test",
            'phone' => '09650001'.str_pad($suffix, 2, '0', STR_PAD_LEFT),
            'attendee_count' => 1,
        ])->assertSessionHasNoErrors();

        return Guest::query()->where('email', "joiner{$suffix}@example.test")->firstOrFail();
    }

    private function answer(Guest $guest, string $status, int $seats = 1)
    {
        return $this->post(route('rsvp.token.store', $guest->invitation_token), [
            'status' => $status,
            'attendee_count' => $status === 'accepted' ? $seats : 0,
        ]);
    }

    // ---------------------------------------------------------------- guards: cases that already work

    public function test_a_guest_with_no_group_is_listed_and_filterable(): void
    {
        $plain = Guest::factory()->for($this->event)->create(['name' => 'Ungrouped Person']);
        $grouped = Guest::factory()->for($this->event)->create(['name' => 'Grouped Person', 'guest_group_id' => $this->group()->id]);

        $this->actingAs($this->host)
            ->get(route('events.guests.index', ['event' => $this->event, 'group' => 'none']))
            ->assertOk()
            ->assertSee('Ungrouped Person')
            ->assertDontSee('Grouped Person');
        $this->assertNull($plain->fresh()->guest_group_id);
        $this->assertNotNull($grouped->fresh()->guest_group_id);
    }

    public function test_a_duplicate_name_is_refused_in_the_event_but_allowed_in_another(): void
    {
        $this->group('Family');
        $other = Event::factory()->for($this->host)->published()->create();

        $this->actingAs($this->host)
            ->post(route('events.guest-groups.store', $this->event), ['name' => 'Family'])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->host)
            ->post(route('events.guest-groups.store', $other), ['name' => 'Family'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, GuestGroup::query()->where('event_id', $this->event->id)->where('name', 'Family')->count());
        $this->assertSame(1, GuestGroup::query()->where('event_id', $other->id)->where('name', 'Family')->count());
    }

    public function test_an_empty_or_blank_name_is_refused_everywhere(): void
    {
        $group = $this->group('Real', null, null);

        foreach (['', '   '] as $name) {
            $this->actingAs($this->host)->post(route('events.guest-groups.store', $this->event), ['name' => $name])->assertSessionHasErrors('name');
            $this->actingAs($this->host)
                ->put(route('events.guest-groups.update', ['event' => $this->event, 'guest_group' => $group->id]), ['name' => $name])
                ->assertSessionHasErrors('name');
        }
        Sanctum::actingAs($this->host);
        $this->postJson("/api/v1/host/events/{$this->event->id}/guest-groups", ['name' => '  '])->assertUnprocessable();

        $this->assertSame('Real', $group->fresh()->name);
    }

    public function test_another_events_group_is_a_404_on_every_route(): void
    {
        $other = Event::factory()->for($this->host)->published()->create(['is_public' => false]);
        $foreign = $this->group('Foreign', $other);
        $params = ['event' => $this->event, 'guest_group' => $foreign->id];

        $this->actingAs($this->host);
        $this->put(route('events.guest-groups.update', $params), ['name' => 'Taken'])->assertNotFound();
        $this->delete(route('events.guest-groups.destroy', $params))->assertNotFound();
        $this->put(route('events.guest-groups.link.update', $params), ['seat_limit' => 3])->assertNotFound();
        $this->patch(route('events.guest-groups.link.toggle', $params))->assertNotFound();
        $this->delete(route('events.guest-groups.link.destroy', $params))->assertNotFound();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_a_guest_cannot_be_put_in_another_events_group(): void
    {
        $other = Event::factory()->for($this->host)->published()->create(['is_public' => false]);
        $foreign = $this->group('Foreign', $other);

        $this->actingAs($this->host)
            ->post(route('events.guests.store', $this->event), ['name' => 'Someone', 'guest_group_id' => $foreign->id])
            ->assertSessionHasErrors('guest_group_id');
    }

    // ---------------------------------------------------------------- Phase 1: nobody escapes review

    public function test_a_link_joiner_re_accepting_after_the_group_is_deleted_is_still_reviewed(): void
    {
        $group = $this->group();
        $joiner = $this->join($group);
        $this->answer($joiner, 'declined');

        app(GuestGroupDeleter::class)->delete($this->event, $group, $this->host->id);
        $this->answer($joiner, 'accepted')->assertSessionHasNoErrors();

        $this->assertNull($joiner->fresh()->guest_group_id);
        $this->assertSame(RsvpApprovalStatus::Pending, $joiner->fresh()->rsvp->host_approval_status);
    }

    public function test_a_link_joiner_re_accepting_after_the_link_is_turned_off_is_still_reviewed(): void
    {
        $group = $this->group();
        $joiner = $this->join($group);
        $this->answer($joiner, 'declined');

        app(GuestGroupDeleter::class)->turnOffLink($this->event, $group, $this->host->id);
        $this->answer($joiner, 'accepted')->assertSessionHasNoErrors();

        $this->assertSame($group->id, $joiner->fresh()->guest_group_id);
        $this->assertSame(RsvpApprovalStatus::Pending, $joiner->fresh()->rsvp->host_approval_status);
    }

    public function test_a_guest_the_host_added_by_hand_is_not_pulled_into_review(): void
    {
        $group = $this->group();
        $mine = Guest::factory()->for($this->event)->create(['guest_group_id' => $group->id]);
        $this->answer($mine, 'accepted')->assertSessionHasNoErrors();

        app(GuestGroupDeleter::class)->delete($this->event, $group);
        $this->answer($mine, 'declined');
        $this->answer($mine, 'accepted');

        $this->assertSame(RsvpApprovalStatus::NotRequired, $mine->fresh()->rsvp->host_approval_status);
    }

    public function test_the_approval_queue_stays_visible_once_the_group_is_gone(): void
    {
        $group = $this->group();
        $this->join($group);
        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->assertTrue($this->event->fresh()->hasRsvpApprovalQueue());
    }

    // ---------------------------------------------------------------- Phase 1: the deleter

    public function test_deleting_a_group_counts_who_is_affected_and_records_it_for_every_member(): void
    {
        $group = $this->group('Committee');
        $joiner = $this->join($group);
        $a = Guest::factory()->for($this->event)->create(['guest_group_id' => $group->id]);
        $b = Guest::factory()->for($this->event)->create(['guest_group_id' => $group->id]);

        $result = app(GuestGroupDeleter::class)->delete($this->event, $group, $this->host->id);

        $this->assertSame(['name' => 'Committee', 'members' => 3, 'joiners' => 1, 'pending' => 1], $result);
        $this->assertNull(GuestGroup::find($group->id));
        $this->assertSame(3, GuestEdit::query()->where('user_id', $this->host->id)->count());
        $this->assertSame(['Committee', null], GuestEdit::query()->where('guest_id', $a->id)->sole()->changes['group']);
        $this->assertTrue(GuestEdit::query()->where('guest_id', $joiner->id)->sole()->changes['link_closed']);
        $this->assertArrayNotHasKey('link_closed', GuestEdit::query()->where('guest_id', $b->id)->sole()->changes);
    }

    public function test_turning_the_link_off_keeps_the_group_and_records_only_the_joiners(): void
    {
        $group = $this->group('Committee');
        $joiner = $this->join($group);
        $member = Guest::factory()->for($this->event)->create(['guest_group_id' => $group->id]);

        $result = app(GuestGroupDeleter::class)->turnOffLink($this->event, $group, $this->host->id);

        $this->assertSame(2, $result['members']);
        $this->assertSame(1, $result['joiners']);
        $fresh = $group->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->rsvp_token);
        $this->assertSame($group->id, $member->fresh()->guest_group_id);
        $this->assertSame(1, GuestEdit::query()->count());
        $this->assertSame($joiner->id, GuestEdit::query()->sole()->guest_id);
    }

    public function test_the_history_lines_are_readable(): void
    {
        $group = $this->group('Committee');
        $joiner = $this->join($group);
        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->assertSame(
            ['Group: Committee → none', 'Group link closed (they signed up through it)'],
            GuestEdit::query()->where('guest_id', $joiner->id)->sole()->lines()
        );
    }

    public function test_a_second_delete_of_the_same_group_is_a_quiet_no_op(): void
    {
        $group = $this->group();
        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $again = app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->assertSame(0, $again['members']);
    }

    public function test_the_deleter_never_touches_another_events_group(): void
    {
        $other = Event::factory()->for($this->host)->published()->create(['is_public' => false]);
        $foreign = $this->group('Foreign', $other);
        Guest::factory()->for($other)->create(['guest_group_id' => $foreign->id]);

        $result = app(GuestGroupDeleter::class)->delete($this->event, $foreign);

        $this->assertSame(0, $result['members']);
        $this->assertNotNull(GuestGroup::find($foreign->id));
        $this->assertSame(0, GuestEdit::query()->count());
    }

    // ---------------------------------------------------------------- Phase 1: what the host is told

    public function test_the_confirm_text_says_what_a_delete_and_a_link_off_will_do(): void
    {
        $group = $this->group('Committee');
        $this->join($group, '1');
        $this->join($group, '2');
        Guest::factory()->for($this->event)->create(['guest_group_id' => $group->id]);

        $page = $this->actingAs($this->host)->get(route('events.guest-groups.index', $this->event))->assertOk();

        $page->assertSee('Remove Committee? 3 guests stay on the list without a group.', false)
            ->assertSee('2 signed up through the link and stay under your review, and 2 are waiting for you.', false)
            ->assertSee('Its shared RSVP link stops working.', false)
            ->assertSee('Turn off this link? The address stops working; 3 members and their RSVPs stay.', false);
    }

    public function test_the_confirm_text_for_a_plain_empty_group_is_short(): void
    {
        $group = $this->group('Empty', null, null);

        $this->assertSame('Remove Empty? It has no guests.', $group->removalWarning());
    }

    public function test_the_page_reports_the_result_of_a_delete(): void
    {
        $group = $this->group('Committee');
        $this->join($group);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->delete(route('events.guest-groups.destroy', ['event' => $this->event, 'guest_group' => $group->id]))
            ->assertSee('Group removed.')
            ->assertSee('had 1 guest; they are still on the list, without a group.', false)
            ->assertSee('1 guest signed up through that link and stays under your review.', false)
            ->assertSee('1 is waiting for you on the guest list.', false);
    }

    public function test_the_page_reports_the_result_of_turning_the_link_off(): void
    {
        $group = $this->group('Committee');
        $this->join($group);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->delete(route('events.guest-groups.link.destroy', ['event' => $this->event, 'guest_group' => $group->id]))
            ->assertSee('Group link turned off.')
            ->assertSee('1 guest signed up through that link and stays under your review.', false);
    }

    public function test_the_api_paths_go_through_the_same_deleter(): void
    {
        Sanctum::actingAs($this->host);
        $deleted = $this->group('Delete me');
        $linked = $this->group('Close me');
        $a = Guest::factory()->for($this->event)->create(['guest_group_id' => $deleted->id]);
        $joiner = $this->join($linked);

        $this->deleteJson("/api/v1/host/events/{$this->event->id}/guest-groups/{$deleted->id}")->assertNoContent();
        $this->patchJson("/api/v1/host/events/{$this->event->id}/guest-groups/{$linked->id}", ['name' => 'Close me', 'seat_limit' => null])->assertOk();

        $this->assertNull(GuestGroup::find($deleted->id));
        $this->assertSame(['Delete me', null], GuestEdit::query()->where('guest_id', $a->id)->sole()->changes['group']);
        $this->assertNull($linked->fresh()->rsvp_token);
        $this->assertTrue(GuestEdit::query()->where('guest_id', $joiner->id)->sole()->changes['link_closed']);
    }

    // ---------------------------------------------------------------- Phase 1: scale

    public function test_deleting_a_group_of_a_thousand_guests_is_a_handful_of_queries(): void
    {
        $group = $this->group('Everyone', null, null);
        Guest::factory()->count(1200)->for($this->event)->create(['guest_group_id' => $group->id]);

        DB::enableQueryLog();
        $result = app(GuestGroupDeleter::class)->delete($this->event, $group, $this->host->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1200, $result['members']);
        $this->assertSame(1200, GuestEdit::query()->count());
        $this->assertLessThan(30, $queries, "a group delete used {$queries} queries");
    }

    // ---------------------------------------------------------------- Phase 2: a closed link says so

    private function joinToken(GuestGroup $group): string
    {
        return (string) $group->fresh()->rsvp_token;
    }

    public function test_the_old_link_of_a_deleted_group_says_it_was_closed(): void
    {
        $group = $this->group('Committee');
        $token = $this->joinToken($group);
        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->get(route('group-rsvp.show', ['token' => $token]))
            ->assertStatus(410)
            ->assertSee('This RSVP link was closed')
            ->assertSee($this->event->name)
            ->assertSee('0977123456')
            ->assertDontSee('Committee');
    }

    public function test_the_old_link_of_a_group_whose_link_was_turned_off_says_it_was_closed(): void
    {
        $group = $this->group('Committee');
        $token = $this->joinToken($group);
        app(GuestGroupDeleter::class)->turnOffLink($this->event, $group);

        $this->get(route('group-rsvp.show', ['token' => $token]))->assertStatus(410)->assertSee('This RSVP link was closed');
    }

    public function test_a_request_posted_to_a_closed_link_gets_the_same_page_and_creates_nobody(): void
    {
        $group = $this->group('Committee');
        $token = $this->joinToken($group);
        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->post(route('group-rsvp.store', ['token' => $token]), [
            'name' => 'Late Person',
            'email' => 'late@example.test',
            'phone' => '0965000999',
            'attendee_count' => 1,
        ])->assertStatus(410)->assertSee('closed');

        $this->assertDatabaseMissing('guests', ['email' => 'late@example.test']);
    }

    public function test_an_unknown_group_link_keeps_the_ordinary_not_found_page(): void
    {
        $this->get(route('group-rsvp.show', ['token' => str_repeat('z', 48)]))
            ->assertNotFound()
            ->assertDontSee('was closed');
    }

    public function test_a_link_that_is_only_closed_by_the_host_is_not_treated_as_removed(): void
    {
        $group = $this->group('Committee');
        $this->actingAs($this->host)
            ->patch(route('events.guest-groups.link.toggle', ['event' => $this->event, 'guest_group' => $group->id]));

        $this->get(route('group-rsvp.show', ['token' => $this->joinToken($group)]))
            ->assertOk()
            ->assertDontSee('This RSVP link was closed');
    }

    public function test_turning_a_link_back_on_gives_a_new_address_and_the_old_one_stays_closed(): void
    {
        $group = $this->group('Committee');
        $old = $this->joinToken($group);
        app(GuestGroupDeleter::class)->turnOffLink($this->event, $group);

        $group->fresh()->enableLink(5);
        $new = $this->joinToken($group);

        $this->assertNotSame($old, $new);
        $this->get(route('group-rsvp.show', ['token' => $new]))->assertOk();
        $this->get(route('group-rsvp.show', ['token' => $old]))->assertStatus(410);
    }

    public function test_a_group_that_never_had_a_link_leaves_nothing_behind(): void
    {
        $group = $this->group('Plain', null, null);

        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->assertSame(0, GroupLinkRemoval::query()->count());
    }

    public function test_the_record_holds_a_hash_not_the_token_and_goes_with_the_event(): void
    {
        $group = $this->group('Committee');
        $token = $this->joinToken($group);
        app(GuestGroupDeleter::class)->delete($this->event, $group);

        $this->assertDatabaseMissing('group_link_removals', ['token_hash' => $token]);
        $record = GroupLinkRemoval::query()->sole();
        $this->assertSame(GroupLinkRemoval::REASON_GROUP_DELETED, $record->reason);
        $this->assertSame(64, strlen($record->token_hash));

        $this->event->forceDelete();

        $this->assertSame(0, GroupLinkRemoval::query()->count());
    }

    public function test_another_events_link_is_not_reported_closed_by_this_events_delete(): void
    {
        $other = Event::factory()->for($this->host)->published()->create(['is_public' => false]);
        $foreign = $this->group('Foreign', $other);
        $token = $this->joinToken($foreign);

        app(GuestGroupDeleter::class)->delete($this->event, $foreign);

        $this->get(route('group-rsvp.show', ['token' => $token]))->assertOk();
        $this->assertSame(0, GroupLinkRemoval::query()->count());
    }

    public function test_privacy_mentions_the_closed_link_record(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Closed group links')->assertSee('turn off its shared RSVP link');
    }

    // ---------------------------------------------------------------- Phase 3: names

    private function createGroupByForm(string $name)
    {
        return $this->actingAs($this->host)->post(route('events.guest-groups.store', $this->event), ['name' => $name]);
    }

    public function test_a_group_name_is_stored_cleaned(): void
    {
        $this->createGroupByForm("  Family \u{200B}  Friends\t")->assertSessionHasNoErrors();

        $this->assertSame('Family Friends', GuestGroup::query()->where('event_id', $this->event->id)->sole()->name);
    }

    public function test_spacing_case_and_invisible_characters_never_make_a_second_group(): void
    {
        $this->createGroupByForm('Family Friends')->assertSessionHasNoErrors();

        foreach (['Family  Friends', 'FAMILY FRIENDS', "Family\u{200B} Friends", ' family friends '] as $variant) {
            $this->createGroupByForm($variant)->assertSessionHasErrors('name');
        }

        $this->assertSame(1, GuestGroup::query()->where('event_id', $this->event->id)->count());
    }

    public function test_a_composed_and_a_decomposed_accent_are_the_same_name(): void
    {
        $this->createGroupByForm("Caf\u{00E9}")->assertSessionHasNoErrors();

        $this->createGroupByForm("Cafe\u{0301}")->assertSessionHasErrors('name');
    }

    public function test_a_name_that_is_only_invisible_characters_is_empty(): void
    {
        $this->createGroupByForm("\u{200B}\u{200D}\u{FEFF}")->assertSessionHasErrors('name');

        $this->assertSame(0, GuestGroup::query()->where('event_id', $this->event->id)->count());
    }

    public function test_a_legacy_name_with_odd_spacing_still_blocks_its_clean_twin(): void
    {
        GuestGroup::query()->create(['event_id' => $this->event->id, 'name' => 'Old  Crowd']);

        $this->createGroupByForm('old crowd')->assertSessionHasErrors('name');
    }

    public function test_the_length_limit_is_the_column_not_255(): void
    {
        $this->createGroupByForm(str_repeat('a', GuestGroup::NAME_MAX))->assertSessionHasNoErrors();
        $this->createGroupByForm(str_repeat('b', GuestGroup::NAME_MAX + 1))->assertSessionHasErrors('name');
        $this->createGroupByForm(str_repeat('c', 255))->assertSessionHasErrors('name');

        $this->assertSame(1, GuestGroup::query()->where('event_id', $this->event->id)->count());
    }

    public function test_a_rename_is_cleaned_checked_and_may_change_only_case(): void
    {
        $family = $this->group('family', null, null);
        $this->group('Friends', null, null);
        $url = route('events.guest-groups.update', ['event' => $this->event, 'guest_group' => $family->id]);

        $this->actingAs($this->host)->put($url, ['name' => 'FRIENDS'])->assertSessionHasErrors('name');
        $this->actingAs($this->host)->put($url, ['name' => str_repeat('x', 200)])->assertSessionHasErrors('name');
        $this->actingAs($this->host)->put($url, ['name' => "  Family\u{200B}  "])->assertSessionHasNoErrors();

        $this->assertSame('Family', $family->fresh()->name);
    }

    public function test_the_api_applies_the_same_rules(): void
    {
        Sanctum::actingAs($this->host);
        $base = "/api/v1/host/events/{$this->event->id}/guest-groups";

        $this->postJson($base, ['name' => 'Family  Friends'])->assertCreated()->assertJsonPath('name', 'Family Friends');
        $this->postJson($base, ['name' => 'family friends'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($base, ['name' => str_repeat('z', 192)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson($base, ['name' => "\u{200B}"])->assertUnprocessable()->assertJsonValidationErrors('name');

        $other = $this->postJson($base, ['name' => 'Other'])->assertCreated()->json('id');
        $this->patchJson("$base/$other", ['name' => 'FAMILY FRIENDS'])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_two_requests_passing_the_check_together_get_a_validation_error_not_a_500(): void
    {
        // Stands in for "both requests read the names before either wrote": the in-PHP check sees nothing, so only the unique index can refuse.
        $blind = new class extends GuestGroupNamer
        {
            protected function takenKeys(Event $event, ?int $ignoreGroupId): array
            {
                return [];
            }
        };
        $blind->create($this->event, 'Racers');

        try {
            $blind->create($this->event, 'Racers');
            $this->fail('the second create should have been refused');
        } catch (ValidationException $e) {
            $this->assertSame(['The name has already been taken.'], $e->errors()['name']);
        }

        $this->assertSame(1, GuestGroup::query()->where('event_id', $this->event->id)->count());
    }

    public function test_the_import_names_a_group_once_however_it_is_written(): void
    {
        $existing = $this->group('Family', null, null);
        $csv = "name,email,group\n"
            ."Ann,ann@example.test,family\n"
            ."Ben,ben@example.test,\"Fam\u{200B}ily \"\n"
            ."Cat,cat@example.test,Family  Friends\n"
            ."Dan,dan@example.test,family friends\n"
            ."Eve,eve@example.test,\n";

        $this->actingAs($this->host)->post(route('events.guests.import.store', $this->event), [
            'file' => UploadedFile::fake()->createWithContent('guests.csv', $csv),
        ]);

        $this->assertEqualsCanonicalizing(['Family', 'Family Friends'], GuestGroup::query()->where('event_id', $this->event->id)->pluck('name')->all());
        $friends = GuestGroup::query()->where('name', 'Family Friends')->sole();
        $this->assertSame(2, Guest::query()->where('guest_group_id', $existing->id)->count());
        $this->assertSame(2, Guest::query()->where('guest_group_id', $friends->id)->count());
        $this->assertNull(Guest::query()->where('email', 'eve@example.test')->sole()->guest_group_id);
    }
}
