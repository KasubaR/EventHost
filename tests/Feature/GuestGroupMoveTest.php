<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * plans/guest-editing-gaps.md Phase 3: a host moving a guest into a group with a seat pool cannot overfill it by accident, a hand move
 * never leaves a guest looking like a group-link signup, and the bulk move skips whoever would not fit.
 */
class GuestGroupMoveTest extends TestCase
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
            'guest_limit' => null,
            'require_rsvp_approval' => false,
        ]);
    }

    private function group(string $name, ?int $seats = null): GuestGroup
    {
        $group = GuestGroup::factory()->for($this->event)->create(['name' => $name]);

        if ($seats !== null) {
            $group->enableLink($seats);
        }

        return $group->fresh();
    }

    private function guest(string $name, ?GuestGroup $group = null, ?int $seats = null, RsvpStatus $status = RsvpStatus::Accepted, array $rsvp = []): Guest
    {
        $guest = Guest::factory()->for($this->event)->create([
            'name' => $name,
            'guest_group_id' => $group?->id,
            'plus_one_allowed' => true,
        ]);

        if ($seats !== null) {
            Rsvp::factory()->for($guest)->create(array_merge([
                'status' => $status,
                'attendee_count' => $status === RsvpStatus::Accepted ? $seats : 0,
            ], $rsvp));
        }

        return $guest;
    }

    private function move(Guest $guest, ?GuestGroup $to, array $extra = [])
    {
        return $this->actingAs($this->host)->patch(
            route('events.guests.update', ['event' => $this->event, 'guest' => $guest]),
            array_merge(['name' => $guest->name, 'email' => $guest->email, 'phone' => $guest->phone, 'guest_group_id' => $to?->id], $extra),
        );
    }

    // ── one guest ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_move_that_would_overfill_the_pool_is_refused_and_says_how_many_seats_are_left(): void
    {
        $pool = $this->group('Committee', 2);
        $this->guest('Member', $pool, 1);
        $mover = $this->guest('Mover', null, 2);

        $this->move($mover, $pool)->assertSessionHasErrors(['guest_group_id' => 'Only 1 seat is left in Committee, and Mover holds 2 seats. Tick "Allow over the pool" to move them anyway.']);

        $this->assertNull($mover->fresh()->guest_group_id);
        $this->assertSame(1, $pool->seatsTaken());
    }

    public function test_a_full_pool_says_it_has_no_seats_left(): void
    {
        $pool = $this->group('Committee', 1);
        $this->guest('Member', $pool, 1);
        $mover = $this->guest('Mover', null, 1);

        $this->move($mover, $pool)->assertSessionHasErrors(['guest_group_id' => 'Committee has no seats left, and Mover holds 1 seat. Tick "Allow over the pool" to move them anyway.']);
    }

    public function test_a_move_that_fits_happens(): void
    {
        $pool = $this->group('Committee', 3);
        $this->guest('Member', $pool, 1);
        $mover = $this->guest('Mover', null, 2);

        $this->move($mover, $pool)->assertSessionHasNoErrors();

        $this->assertSame($pool->id, $mover->fresh()->guest_group_id);
        $this->assertSame(3, $pool->seatsTaken());
        $this->assertSame(0, $pool->seatsRemaining());
    }

    public function test_the_host_can_go_over_the_pool_with_the_tick(): void
    {
        $pool = $this->group('Committee', 1);
        $this->guest('Member', $pool, 1);
        $mover = $this->guest('Mover', null, 2);

        $this->move($mover, $pool, ['allow_over_pool' => '1'])->assertSessionHasNoErrors();

        $this->assertSame($pool->id, $mover->fresh()->guest_group_id);
        $this->assertSame(3, $pool->seatsTaken());
        $this->assertSame(0, $pool->seatsRemaining(), 'never reads below zero');
    }

    public function test_a_guest_who_holds_no_seats_always_fits(): void
    {
        $pool = $this->group('Committee', 1);
        $this->guest('Member', $pool, 1);

        $noAnswer = $this->guest('No Answer');
        $declined = $this->guest('Declined', null, 1, RsvpStatus::Declined);
        $maybe = $this->guest('Maybe', null, 1, RsvpStatus::Maybe);
        $rejected = $this->guest('Rejected', null, 2, RsvpStatus::Accepted, ['host_approval_status' => RsvpApprovalStatus::Rejected]);

        foreach ([$noAnswer, $declined, $maybe, $rejected] as $guest) {
            $this->move($guest, $pool)->assertSessionHasNoErrors();
            $this->assertSame($pool->id, $guest->fresh()->guest_group_id, $guest->name);
        }
    }

    public function test_a_pending_request_holds_its_seats(): void
    {
        $pool = $this->group('Committee', 1);
        $mover = $this->guest('Pending Mover', null, 2, RsvpStatus::Accepted, ['host_approval_status' => RsvpApprovalStatus::Pending]);

        $this->move($mover, $pool)->assertSessionHasErrors('guest_group_id');
    }

    public function test_moving_into_an_unpooled_group_or_out_of_a_pool_needs_no_check(): void
    {
        $plain = $this->group('Family');
        $pool = $this->group('Committee', 1);
        $member = $this->guest('Member', $pool, 1);
        $mover = $this->guest('Big Party', null, 2);

        $this->move($mover, $plain)->assertSessionHasNoErrors();
        $this->assertSame($plain->id, $mover->fresh()->guest_group_id);

        $this->move($member, null)->assertSessionHasNoErrors();
        $this->assertNull($member->fresh()->guest_group_id);
        $this->assertSame(0, $pool->seatsTaken(), 'moving out frees the seats');
    }

    public function test_editing_a_guest_already_in_an_overfull_pool_is_not_refused(): void
    {
        $pool = $this->group('Committee', 1);
        $a = $this->guest('A', $pool, 1);
        $this->guest('B', $pool, 1);

        $this->move($a, $pool, ['name' => 'A Renamed'])->assertSessionHasNoErrors();

        $this->assertSame('A Renamed', $a->fresh()->name);
    }

    public function test_a_hand_move_clears_the_link_joiner_flag_so_nothing_is_forced_into_review(): void
    {
        $source = $this->group('Source', 5);
        $target = $this->group('Target', 5);
        $joiner = $this->guest('Joiner', $source, 1);
        $joiner->forceFill(['group_link_joined_at' => now()->subDay(), 'invitation_token' => 'tok_joiner'])->save();

        $this->move($joiner, $target)->assertSessionHasNoErrors();
        $this->assertNull($joiner->fresh()->group_link_joined_at);

        // Declining and accepting again is no longer held for the host.
        $this->post(route('rsvp.token.store', 'tok_joiner'), ['status' => 'declined', 'attendee_count' => 0])->assertSessionHasNoErrors();
        $this->post(route('rsvp.token.store', 'tok_joiner'), ['status' => 'accepted', 'attendee_count' => 1])->assertSessionHasNoErrors();

        $this->assertNotSame(RsvpApprovalStatus::Pending, $joiner->fresh()->rsvp->host_approval_status);
    }

    public function test_a_save_that_does_not_change_the_group_keeps_the_link_joiner_flag(): void
    {
        $pool = $this->group('Committee', 5);
        $joiner = $this->guest('Joiner', $pool, 1);
        $joiner->forceFill(['group_link_joined_at' => now()->subDay()])->save();

        $this->move($joiner, $pool, ['name' => 'Joiner Renamed'])->assertSessionHasNoErrors();

        $this->assertNotNull($joiner->fresh()->group_link_joined_at);
    }

    public function test_the_edit_page_offers_the_override_after_a_refusal(): void
    {
        $pool = $this->group('Committee', 1);
        $this->guest('Member', $pool, 1);
        $mover = $this->guest('Mover', null, 1);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->from(route('events.guests.edit', ['event' => $this->event, 'guest' => $mover]))
            ->patch(route('events.guests.update', ['event' => $this->event, 'guest' => $mover]), [
                'name' => 'Mover', 'email' => $mover->email, 'phone' => $mover->phone, 'guest_group_id' => $pool->id,
            ])
            ->assertSee('Allow this to go over the group')
            ->assertSee('has no seats left');
    }

    public function test_the_api_refuses_and_overrides_the_same_way(): void
    {
        $pool = $this->group('Committee', 1);
        $this->guest('Member', $pool, 1);
        $mover = $this->guest('Mover', null, 1);
        Sanctum::actingAs($this->host);

        $payload = ['name' => 'Mover', 'email' => $mover->email, 'phone' => $mover->phone, 'guest_group_id' => $pool->id];

        $this->patchJson(route('api.v1.host.events.guests.update', ['event' => $this->event, 'guest' => $mover]), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('guest_group_id');
        $this->assertNull($mover->fresh()->guest_group_id);

        $this->patchJson(route('api.v1.host.events.guests.update', ['event' => $this->event, 'guest' => $mover]), $payload + ['allow_over_pool' => true])
            ->assertOk();
        $this->assertSame($pool->id, $mover->fresh()->guest_group_id);
    }

    // ── bulk ───────────────────────────────────────────────────────────────────────────────────────

    public function test_the_bulk_move_fills_the_pool_and_skips_the_rest(): void
    {
        $pool = $this->group('Committee', 2);
        $guests = collect(['One', 'Two', 'Three'])->map(fn ($n) => $this->guest($n, null, 1));

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), [
                'action' => 'assign_group',
                'guest_ids' => $guests->pluck('id')->all(),
                'guest_group_id' => $pool->id,
            ])
            ->assertSessionHas('bulk_count', 2)
            ->assertSessionHas('bulk_skipped', 1)
            ->assertSessionHas('bulk_skip_reason', fn ($r) => str_contains($r, 'Committee has no seats left') && str_contains($r, 'Move them from their own page'));

        $this->assertSame(2, Guest::query()->where('guest_group_id', $pool->id)->count());
        $this->assertSame(2, $pool->fresh()->seatsTaken());
    }

    public function test_the_list_reports_the_skipped_guests(): void
    {
        $pool = $this->group('Committee', 1);
        $a = $this->guest('One', null, 1);
        $b = $this->guest('Two', null, 1);

        $this->actingAs($this->host)
            ->followingRedirects()
            ->post(route('events.guests.bulk', $this->event), [
                'action' => 'assign_group',
                'guest_ids' => [$a->id, $b->id],
                'guest_group_id' => $pool->id,
            ])
            ->assertSee('1 guest updated')
            ->assertSee('1 not moved')
            ->assertSee('has no seats left');
    }

    public function test_the_bulk_move_to_an_unpooled_group_or_to_none_moves_everyone_and_clears_the_flag(): void
    {
        $plain = $this->group('Family');
        $source = $this->group('Source', 5);
        $a = $this->guest('A', $source, 2);
        $b = $this->guest('B', $source, 2);
        $a->forceFill(['group_link_joined_at' => now()])->save();

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'assign_group', 'guest_ids' => [$a->id, $b->id], 'guest_group_id' => $plain->id])
            ->assertSessionHas('bulk_count', 2)
            ->assertSessionHas('bulk_skipped', 0);

        $this->assertSame($plain->id, $a->fresh()->guest_group_id);
        $this->assertNull($a->fresh()->group_link_joined_at);

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'assign_group', 'guest_ids' => [$a->id, $b->id], 'guest_group_id' => ''])
            ->assertSessionHas('bulk_count', 2);

        $this->assertNull($a->fresh()->guest_group_id);
        $this->assertNull($b->fresh()->guest_group_id);
    }

    public function test_guests_already_in_the_target_group_are_not_counted_against_it_again(): void
    {
        $pool = $this->group('Committee', 2);
        $inside = $this->guest('Inside', $pool, 2);
        $outside = $this->guest('Outside', null, 1);

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'assign_group', 'guest_ids' => [$inside->id, $outside->id], 'guest_group_id' => $pool->id])
            ->assertSessionHas('bulk_count', 1 + 0)
            ->assertSessionHas('bulk_skipped', 1);

        $this->assertSame($pool->id, $inside->fresh()->guest_group_id);
        $this->assertNull($outside->fresh()->guest_group_id);
    }

    public function test_the_api_bulk_move_reports_skips_and_the_reason(): void
    {
        $pool = $this->group('Committee', 1);
        $a = $this->guest('One', null, 1);
        $b = $this->guest('Two', null, 1);
        Sanctum::actingAs($this->host);

        $this->postJson(route('api.v1.host.events.guests.bulk', $this->event), [
            'action' => 'assign_group',
            'guest_ids' => [$a->id, $b->id],
            'guest_group_id' => $pool->id,
        ])
            ->assertOk()
            ->assertJsonPath('affected_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped_reason', fn ($r) => str_contains($r, 'Committee has no seats left'));

        $this->postJson(route('api.v1.host.events.guests.bulk', $this->event), [
            'action' => 'assign_table',
            'guest_ids' => [$a->id],
        ])
            ->assertOk()
            ->assertJsonPath('skipped_reason', null);
    }
}
