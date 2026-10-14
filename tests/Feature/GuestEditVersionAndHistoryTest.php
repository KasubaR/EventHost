<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\GuestEdit;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-editing-gaps.md Phase 5: a guest edit made on a page that has gone stale is refused with the host's edits still on screen,
 * and every saved edit of a guest's details is recorded with who made it and the old and new values.
 */
class GuestEditVersionAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->host = User::factory()->pro()->create(['name' => 'Chanda Host']);
        $this->event = Event::factory()->for($this->host)->create();
    }

    private function guest(array $overrides = []): Guest
    {
        return Guest::factory()->for($this->event)->create(array_merge([
            'name' => 'Edit Guest',
            'email' => 'edit@example.test',
            'phone' => '0971234567',
            'plus_one_allowed' => false,
        ], $overrides));
    }

    private function save(Guest $guest, array $changes = [], ?string $version = null)
    {
        $payload = array_merge(['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone], $changes);
        $payload['guest_version'] = $version ?? $guest->editVersion();

        return $this->actingAs($this->host)->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), $payload);
    }

    // ── version ────────────────────────────────────────────────────────────────────────────────────

    public function test_the_version_follows_what_the_form_edits_and_nothing_else(): void
    {
        $guest = $this->guest();
        $version = $guest->editVersion();

        $this->assertSame($version, $guest->fresh()->editVersion());

        foreach ([
            ['name' => 'Renamed'], ['email' => 'other@example.test'], ['phone' => '0961112222'], ['plus_one_allowed' => true],
            ['invitation_sent' => true], ['invitation_token' => 'new-token-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'],
        ] as $change) {
            $changed = $guest->fresh();
            $changed->forceFill($change);
            $this->assertNotSame($version, $changed->editVersion(), json_encode($change));
        }

        $untouched = $guest->fresh();
        $untouched->forceFill(['rsvp_reminders_sent' => ['7'], 'checked_in_at' => now()]);
        $this->assertSame($version, $untouched->editVersion(), 'reminder state and check-in are not edited here');
    }

    public function test_a_save_from_a_fresh_page_goes_through(): void
    {
        $guest = $this->guest();

        $this->save($guest, ['name' => 'Renamed'])->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $guest->fresh()->name);
    }

    public function test_a_save_from_a_stale_page_is_refused_with_one_clear_error(): void
    {
        $guest = $this->guest();
        $opened = $guest->editVersion();

        // Another tab saves first.
        $this->save($guest, ['name' => 'Saved First']);
        $this->assertSame('Saved First', $guest->fresh()->name);

        // The stale tab (still at the old version) tries to save a different edit, and a field that would also have failed.
        $this->save($guest->fresh(), ['name' => 'Stale Edit', 'phone' => 'not a phone'], $opened)
            ->assertSessionHasErrors(['guest_version' => 'This guest was changed somewhere else since you opened this page, so your edits were not saved. Open the latest version and make them again.'])
            ->assertSessionDoesntHaveErrors(['phone', 'name']);

        $this->assertSame('Saved First', $guest->fresh()->name);
    }

    public function test_a_bulk_change_between_open_and_save_also_counts(): void
    {
        $guest = $this->guest();
        $opened = $guest->editVersion();
        $group = GuestGroup::factory()->for($this->event)->create();

        $this->actingAs($this->host)->post(route('events.guests.bulk', $this->event), [
            'action' => 'assign_group', 'guest_ids' => [$guest->id], 'guest_group_id' => $group->id,
        ])->assertSessionHas('bulk_count', 1);

        $this->save($guest->fresh(), ['name' => 'Stale'], $opened)->assertSessionHasErrors('guest_version');
    }

    public function test_the_page_keeps_the_old_version_after_a_refusal_so_a_stale_tab_stays_refused(): void
    {
        $guest = $this->guest();
        $opened = $guest->editVersion();
        $this->save($guest, ['name' => 'Saved First']);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->from(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), [
                'name' => 'Stale Edit', 'email' => 'edit@example.test', 'phone' => '0971234567', 'guest_version' => $opened,
            ])
            ->assertSee('changed somewhere else')
            ->assertSee('Open the latest version')
            ->assertSee('value="'.$opened.'"', false)
            ->assertSee('Stale Edit');
    }

    public function test_the_edit_page_carries_the_current_version(): void
    {
        $guest = $this->guest();

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertOk()
            ->assertSee('name="guest_version" value="'.$guest->editVersion().'"', false);
    }

    public function test_without_a_version_the_last_write_wins_as_before(): void
    {
        $guest = $this->guest();

        $this->actingAs($this->host)
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $guest]), ['name' => 'No Version', 'email' => $guest->email, 'phone' => $guest->phone])
            ->assertSessionHasNoErrors();

        $this->assertSame('No Version', $guest->fresh()->name);
    }

    public function test_the_api_reports_the_version_and_honours_it(): void
    {
        $guest = $this->guest();
        Sanctum::actingAs($this->host);

        $version = $this->getJson(route('api.v1.host.events.guests.show', ['event' => $this->event, 'guest' => $guest]))->json('guest.version');
        $this->assertSame($guest->editVersion(), $version);

        $url = route('api.v1.host.events.guests.update', ['event' => $this->event, 'guest' => $guest]);
        $body = ['name' => 'Api Edit', 'email' => $guest->email, 'phone' => $guest->phone];

        $this->patchJson($url, $body + ['guest_version' => $version])->assertOk()->assertJsonPath('version', $guest->fresh()->editVersion());

        $this->patchJson($url, ['name' => 'Again'] + $body + ['guest_version' => $version])
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_version');

        $this->patchJson($url, ['name' => 'No Version Sent'] + $body)->assertOk();
        $this->assertSame('No Version Sent', $guest->fresh()->name);
    }

    // ── history ────────────────────────────────────────────────────────────────────────────────────

    public function test_an_edit_records_who_changed_what_with_old_and_new_values(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create(['name' => 'Cousins']);
        $table = EventTable::factory()->for($this->event)->create(['label' => 'Table 4']);
        $guest = $this->guest();

        $this->save($guest, [
            'name' => 'Renamed Guest', 'email' => 'new@example.test', 'phone' => '0961112222',
            'guest_group_id' => $group->id, 'event_table_id' => $table->id, 'plus_one_allowed' => '1',
        ])->assertSessionHasNoErrors();

        $edit = GuestEdit::query()->where('guest_id', $guest->id)->sole();

        $this->assertSame($this->host->id, $edit->user_id);
        $this->assertSame(['Edit Guest', 'Renamed Guest'], $edit->changes['name']);
        $this->assertSame(['edit@example.test', 'new@example.test'], $edit->changes['email']);
        $this->assertSame(['0971234567', '0961112222'], $edit->changes['phone']);
        $this->assertSame([null, 'Cousins'], $edit->changes['group']);
        $this->assertSame([null, 'Table 4'], $edit->changes['table']);
        $this->assertSame([false, true], $edit->changes['plus_one']);
    }

    public function test_a_save_that_changes_nothing_records_nothing(): void
    {
        $guest = $this->guest();

        $this->save($guest)->assertSessionHasNoErrors();

        $this->assertSame(0, GuestEdit::query()->count());
    }

    public function test_only_the_fields_that_changed_are_recorded(): void
    {
        $guest = $this->guest();

        $this->save($guest, ['phone' => '0961112222'])->assertSessionHasNoErrors();

        $changes = GuestEdit::query()->sole()->changes;
        $this->assertSame(['phone'], array_keys(array_diff_key($changes, ['invitation_reset' => 1])));
    }

    public function test_replacing_the_link_is_recorded_without_the_link(): void
    {
        $guest = $this->guest(['invitation_token' => 'secret-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);

        $this->actingAs($this->host)->post(route('events.guests.regenerate-link', ['event' => $this->event, 'guest' => $guest]))->assertSessionHas('guest_link_replaced');

        $edit = GuestEdit::query()->sole();
        $this->assertSame(['link_replaced' => true], $edit->changes);
        $this->assertStringNotContainsString('secret-token', json_encode($edit->changes));
        $this->assertSame($this->host->id, $edit->user_id);

        $this->save($guest->fresh(), ['regenerate_invitation_token' => '1']);
        $this->assertTrue(GuestEdit::query()->latest('id')->first()->changes['link_replaced']);
    }

    public function test_a_contact_reset_and_an_over_pool_move_are_noted(): void
    {
        $guest = $this->guest(['invitation_sent' => true, 'invitation_sent_at' => now()]);

        $this->save($guest, ['email' => 'new@example.test'])->assertSessionHasNoErrors();

        $this->assertTrue(GuestEdit::query()->sole()->changes['invitation_reset']);

        $pool = GuestGroup::factory()->for($this->event)->create(['name' => 'Committee']);
        $pool->enableLink(1);
        $member = $this->guest(['name' => 'Member', 'email' => 'm@example.test', 'phone' => '0962223333', 'guest_group_id' => $pool->id]);
        Rsvp::factory()->for($member)->create(['status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
        $mover = $this->guest(['name' => 'Mover', 'email' => 'mv@example.test', 'phone' => '0963334444']);
        Rsvp::factory()->for($mover)->create(['status' => RsvpStatus::Accepted, 'attendee_count' => 1]);

        $this->save($mover, ['guest_group_id' => $pool->id, 'allow_over_pool' => '1'])->assertSessionHasNoErrors();

        $edit = GuestEdit::query()->where('guest_id', $mover->id)->sole();
        $this->assertTrue($edit->changes['over_pool']);
        $this->assertSame([null, 'Committee'], $edit->changes['group']);
    }

    public function test_a_bulk_group_move_is_recorded_for_each_guest(): void
    {
        $group = GuestGroup::factory()->for($this->event)->create(['name' => 'Friends']);
        $a = $this->guest(['email' => 'a@example.test', 'phone' => '0961110001']);
        $b = $this->guest(['email' => 'b@example.test', 'phone' => '0961110002']);

        $this->actingAs($this->host)->post(route('events.guests.bulk', $this->event), [
            'action' => 'assign_group', 'guest_ids' => [$a->id, $b->id], 'guest_group_id' => $group->id,
        ])->assertSessionHas('bulk_count', 2);

        $this->assertSame(2, GuestEdit::query()->count());
        $this->assertSame([null, 'Friends'], GuestEdit::query()->where('guest_id', $a->id)->sole()->changes['group']);
        $this->assertSame($this->host->id, GuestEdit::query()->where('guest_id', $b->id)->sole()->user_id);
    }

    public function test_the_edit_page_lists_the_history_newest_first(): void
    {
        $guest = $this->guest();
        $this->save($guest, ['name' => 'First Rename']);
        $this->save($guest->fresh(), ['name' => 'Second Rename']);

        $html = $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertOk()
            ->assertSee('Edit history')
            ->assertSee('by Chanda Host')
            ->assertSee('Name: First Rename → Second Rename')
            ->getContent();

        $this->assertLessThan(strpos($html, 'Name: Edit Guest → First Rename'), strpos($html, 'Name: First Rename → Second Rename'));
    }

    public function test_a_guest_with_no_edits_says_so(): void
    {
        $guest = $this->guest();

        $this->actingAs($this->host)
            ->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertSee('No edits to this guest')->assertSee('details have been recorded');
    }

    public function test_the_history_goes_when_the_guest_is_deleted(): void
    {
        $guest = $this->guest();
        $this->save($guest, ['name' => 'Renamed']);
        $this->assertSame(1, GuestEdit::query()->count());

        $guest->delete();

        $this->assertSame(0, GuestEdit::query()->count());
    }
}
