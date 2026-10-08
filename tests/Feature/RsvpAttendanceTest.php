<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Exceptions\RsvpBusyException;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use App\Models\User;
use App\Rules\AttendeeCount;
use App\Rules\GuestLimitNotBelowConfirmed;
use App\Services\RsvpSubmissionService;
use App\Support\EventAttendance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/rsvp-attendance.md: zero, negative and huge counts get one clear sentence on every channel; both seat limits are
 * weighed before either refuses; a lowered guest limit is re-checked under the lock; a busy event answers "try again".
 */
class RsvpAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->host = User::factory()->pro()->create();
    }

    private function event(array $overrides = []): Event
    {
        return Event::factory()->for($this->host)->published()->create(array_merge([
            'is_public' => true,
            'rsvp_deadline' => null,
            'allow_plus_one' => false,
            'guest_limit' => null,
        ], $overrides));
    }

    private function guest(Event $event, string $token, array $overrides = []): Guest
    {
        return Guest::factory()->for($event)->create(array_merge(['invitation_token' => $token, 'plus_one_allowed' => false], $overrides));
    }

    private function token(string $token, mixed $count, string $status = 'accepted')
    {
        return $this->post(route('rsvp.token.store', ['token' => $token]), ['status' => $status, 'attendee_count' => $count]);
    }

    // ── Phase 1: one rule for the count ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function badCounts(): array
    {
        return [
            'zero' => [0, 'Choose at least 1, or answer "Not attending" instead.'],
            'zero as text' => ['0', 'Choose at least 1, or answer "Not attending" instead.'],
            'negative' => [-1, 'Choose at least 1, or answer "Not attending" instead.'],
            'minus zero' => ['-0', 'Choose at least 1, or answer "Not attending" instead.'],
            'over the maximum' => [3, 'You can RSVP for at most 2 people, including yourself.'],
            'huge' => [PHP_INT_MAX, 'You can RSVP for at most 2 people, including yourself.'],
            'huge text' => ['99999999999999999999', 'You can RSVP for at most 2 people, including yourself.'],
            'past the column' => ['4294967296', 'You can RSVP for at most 2 people, including yourself.'],
            'decimal' => ['2.5', 'Please choose how many people are coming.'],
            'whole decimal' => ['2.0', 'Please choose how many people are coming.'],
            'exponent' => ['1e0', 'Please choose how many people are coming.'],
            'words' => ['two', 'Please choose how many people are coming.'],
            'array' => [['2'], 'Please choose how many people are coming.'],
        ];
    }

    #[DataProvider('badCounts')]
    public function test_a_bad_count_gets_one_clear_sentence_and_saves_nothing(mixed $count, string $message): void
    {
        $event = $this->event(['allow_plus_one' => true]);
        $guest = $this->guest($event, 'tok_bad', ['plus_one_allowed' => true]);

        $response = $this->token('tok_bad', $count);

        $response->assertSessionHasErrors(['attendee_count' => $message]);
        $this->assertCount(1, session('errors')->get('attendee_count'), 'one sentence per mistake, not one per rule');
        $this->assertNull(Rsvp::query()->where('guest_id', $guest->id)->first());
        $this->assertSame(0, RsvpChange::query()->count());
    }

    public function test_a_solo_guest_is_told_they_can_rsvp_for_themselves_only(): void
    {
        $this->guest($this->event(), 'tok_solo');

        $this->token('tok_solo', 2)->assertSessionHasErrors(['attendee_count' => 'You can RSVP for yourself only.']);
    }

    public function test_padded_and_signed_whole_numbers_are_accepted_the_same_way_everywhere(): void
    {
        $event = $this->event(['allow_plus_one' => true]);
        $guest = $this->guest($event, 'tok_pad', ['plus_one_allowed' => true]);

        $this->token('tok_pad', ' 2 ')->assertSessionHasNoErrors();
        $this->assertSame(2, (int) $guest->rsvp()->first()->attendee_count);

        $this->token('tok_pad', '+1')->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $guest->rsvp()->first()->attendee_count);
    }

    public function test_declining_ignores_whatever_count_was_posted(): void
    {
        $event = $this->event();
        $guest = $this->guest($event, 'tok_dec');

        $this->token('tok_dec', -5, 'declined')->assertSessionHasNoErrors();
        $this->token('tok_dec', 'banana', 'maybe')->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $guest->rsvp()->first()->attendee_count);
    }

    public function test_a_missing_count_on_an_acceptance_asks_for_one(): void
    {
        $this->guest($this->event(), 'tok_blank');

        $this->post(route('rsvp.token.store', ['token' => 'tok_blank']), ['status' => 'accepted'])
            ->assertSessionHasErrors(['attendee_count' => 'Please choose how many people are coming.']);
    }

    public function test_the_api_gives_the_same_sentence(): void
    {
        $this->guest($this->event(), 'tok_api');

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'tok_api']), ['status' => 'accepted', 'attendee_count' => -3])
            ->assertStatus(422)
            ->assertJsonPath('errors.attendee_count.0', 'Choose at least 1, or answer "Not attending" instead.')
            ->assertJsonCount(1, 'errors.attendee_count');

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'tok_api']), ['status' => 'accepted', 'attendee_count' => 2.5])
            ->assertStatus(422)
            ->assertJsonPath('errors.attendee_count.0', 'Please choose how many people are coming.');
    }

    public function test_the_open_form_uses_the_same_rule(): void
    {
        $event = $this->event();

        $this->post(route('rsvp.open.store', ['slug' => $event->slug]), [
            'name' => 'Sam Open', 'email' => 'sam@example.com', 'phone' => '0977123456', 'status' => 'accepted', 'attendee_count' => -1,
        ])->assertSessionHasErrors(['attendee_count' => 'Choose at least 1, or answer "Not attending" instead.']);

        $this->assertSame(0, Guest::query()->where('email', 'sam@example.com')->count());
    }

    public function test_the_group_link_uses_the_same_rule(): void
    {
        $event = $this->event(['allow_plus_one' => true]);
        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink(10);
        $group = $group->fresh();

        $post = fn ($count) => $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), [
            'name' => 'Mwila Banda', 'email' => 'mwila@example.test', 'phone' => '0965000111', 'attendee_count' => $count,
        ]);

        $post(0)->assertSessionHasErrors(['attendee_count' => 'Choose at least 1, or answer "Not attending" instead.']);
        $post(-4)->assertSessionHasErrors(['attendee_count' => 'Choose at least 1, or answer "Not attending" instead.']);
        $post('99999999999999999999')->assertSessionHasErrors(['attendee_count' => 'You can RSVP for at most 2 people, including yourself.']);
        $this->assertSame(0, Guest::query()->where('email', 'mwila@example.test')->count());
    }

    public function test_the_host_override_uses_the_same_rule(): void
    {
        $event = $this->event();
        $guest = $this->guest($event, 'tok_host');

        $set = fn ($count) => $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.set', ['event' => $event, 'guest' => $guest->id]), ['status' => 'accepted', 'attendee_count' => $count]);

        $set(0)->assertSessionHasErrors(['attendee_count' => 'Choose at least 1, or answer "Not attending" instead.']);
        $set(3)->assertSessionHasErrors(['attendee_count' => 'You can RSVP for at most 2 people, including yourself.']);
        $this->assertNull(Rsvp::query()->where('guest_id', $guest->id)->first());
    }

    public function test_the_service_refuses_a_count_a_channel_forgot_to_check(): void
    {
        $event = $this->event();
        $guest = $this->guest($event, 'tok_svc');

        foreach ([0, -1, 7] as $count) {
            try {
                app(RsvpSubmissionService::class)->submit($event, $guest, ['status' => RsvpStatus::Accepted, 'attendee_count' => $count]);
                $this->fail("A count of {$count} was accepted.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('attendee_count', $e->errors());
            }
        }

        $this->assertNull(Rsvp::query()->where('guest_id', $guest->id)->first());
    }

    public function test_parse_only_reads_whole_numbers_in_digits(): void
    {
        $this->assertSame(2, AttendeeCount::parse(2));
        $this->assertSame(2, AttendeeCount::parse(' +2 '));
        $this->assertSame(-3, AttendeeCount::parse('-3'));
        $this->assertNull(AttendeeCount::parse(2.0));
        $this->assertNull(AttendeeCount::parse('2.0'));
        $this->assertNull(AttendeeCount::parse('12345'));
        $this->assertNull(AttendeeCount::parse(null));
        $this->assertNull(AttendeeCount::parse(true));
    }

    // ── Phase 2: both limits are weighed ───────────────────────────────────────────────────────────

    private function pooledGuest(Event $event, int $poolSeats, string $token): Guest
    {
        $group = GuestGroup::factory()->for($event)->create();
        $group->enableLink($poolSeats);

        return $this->guest($event, $token, ['guest_group_id' => $group->id, 'plus_one_allowed' => true]);
    }

    public function test_a_pool_with_one_seat_left_says_so_and_offers_it(): void
    {
        $event = $this->event(['allow_plus_one' => true]);
        $guest = $this->pooledGuest($event, 1, 'tok_pool');

        $this->token('tok_pool', 2)
            ->assertSessionHasErrors(['status' => 'Only 1 seat is left for this group. You can RSVP for yourself only.'])
            ->assertSessionHasInput('attendee_count', 1);

        $this->token('tok_pool', 1)->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $guest->rsvp()->first()->attendee_count);
    }

    public function test_a_full_pool_keeps_its_original_sentence(): void
    {
        $event = $this->event(['allow_plus_one' => true]);
        $this->pooledGuest($event, 1, 'tok_pool_a');
        $first = Guest::query()->where('invitation_token', 'tok_pool_a')->first();
        $second = $this->guest($event, 'tok_pool_b', ['guest_group_id' => $first->guest_group_id]);

        $this->token('tok_pool_a', 1)->assertSessionHasNoErrors();
        $this->token('tok_pool_b', 1)->assertSessionHasErrors(['status' => 'This group has no seats left. Please call the host for more information.']);
        $this->assertNull($second->rsvp()->first());
    }

    public function test_the_tighter_limit_is_the_one_reported_and_it_is_named(): void
    {
        // The event has two seats left, the group one: the group is the one to tell the guest about.
        $event = $this->event(['allow_plus_one' => true, 'guest_limit' => 2]);
        $this->pooledGuest($event, 1, 'tok_tight');

        $this->token('tok_tight', 2)->assertSessionHasErrors(['status' => 'Only 1 seat is left for this group. You can RSVP for yourself only.']);
    }

    public function test_the_event_limit_is_named_when_it_is_the_tighter_one(): void
    {
        $event = $this->event(['allow_plus_one' => true, 'guest_limit' => 1]);
        $this->pooledGuest($event, 5, 'tok_ev');

        $this->token('tok_ev', 2)->assertSessionHasErrors(['status' => 'Only 1 seat is left for confirmed attendees. You can RSVP for yourself only.']);
    }

    public function test_a_tie_reports_the_event_limit(): void
    {
        $event = $this->event(['allow_plus_one' => true, 'guest_limit' => 1]);
        $this->pooledGuest($event, 1, 'tok_tie');

        $this->token('tok_tie', 2)->assertSessionHasErrors(['status' => 'Only 1 seat is left for confirmed attendees. You can RSVP for yourself only.']);
    }

    public function test_the_host_can_go_past_both_limits_with_the_tick(): void
    {
        $event = $this->event(['allow_plus_one' => true, 'guest_limit' => 1]);
        $guest = $this->pooledGuest($event, 1, 'tok_over');

        $this->actingAs($this->host)
            ->patch(route('events.guests.rsvp.set', ['event' => $event, 'guest' => $guest->id]), ['status' => 'accepted', 'attendee_count' => 2, 'allow_over_limit' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $guest->rsvp()->first()->attendee_count);
        $this->assertTrue((bool) RsvpChange::query()->where('guest_id', $guest->id)->latest('id')->first()->over_limit);
    }

    // ── Phase 3: lowering the limit is re-checked under the lock ───────────────────────────────────

    public function test_the_save_rechecks_held_seats_once_it_has_the_lock(): void
    {
        $event = $this->event(['guest_limit' => 10]);
        foreach (['a', 'b', 'c'] as $i => $t) {
            $g = $this->guest($event, "tok_{$t}");
            Rsvp::query()->create(['event_id' => $event->id, 'guest_id' => $g->id, 'status' => RsvpStatus::Accepted, 'attendee_count' => 1]);
        }

        GuestLimitNotBelowConfirmed::assertHolds($event->id, 3);
        GuestLimitNotBelowConfirmed::assertHolds($event->id, null);
        GuestLimitNotBelowConfirmed::assertHolds($event->id, '');

        try {
            GuestLimitNotBelowConfirmed::assertHolds($event->id, 2);
            $this->fail('A limit under the confirmed seats was accepted.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("can't be lower than the 3 seats", $e->errors()['guest_limit'][0]);
        }
    }

    // ── Phase 4: a busy event ───────────────────────────────────────────────────────────────────────

    public function test_a_busy_event_asks_the_guest_to_try_again_on_the_web(): void
    {
        $this->guest($this->event(), 'tok_busy');
        $this->mock(RsvpSubmissionService::class, fn ($m) => $m->shouldReceive('submit')->andThrow(new RsvpBusyException));

        $this->token('tok_busy', 1)
            ->assertRedirect(route('rsvp.token.show', ['token' => 'tok_busy']))
            ->assertSessionHasErrors('status')
            ->assertSessionHasInput('attendee_count', 1);
    }

    public function test_a_busy_event_is_a_503_with_a_code_for_the_api(): void
    {
        $this->guest($this->event(), 'tok_busy_api');
        $this->mock(RsvpSubmissionService::class, fn ($m) => $m->shouldReceive('submit')->andThrow(new RsvpBusyException));

        $this->postJson(route('api.v1.rsvp.token.store', ['token' => 'tok_busy_api']), ['status' => 'accepted', 'attendee_count' => 1])
            ->assertStatus(503)
            ->assertJsonPath('code', 'rsvp_busy')
            ->assertHeader('Retry-After', '5');
    }

    public function test_only_lock_failures_are_treated_as_busy(): void
    {
        $isLockFailure = new \ReflectionMethod(RsvpSubmissionService::class, 'isLockFailure');

        $timeout = new QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'));
        $deadlock = new QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'));
        $other = new QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[42S02]: Base table or view not found'));

        $this->assertTrue($isLockFailure->invoke(null, $timeout));
        $this->assertTrue($isLockFailure->invoke(null, $deadlock));
        $this->assertFalse($isLockFailure->invoke(null, $other));
    }

    public function test_a_lock_failure_inside_an_outer_transaction_is_left_for_the_outer_one(): void
    {
        // RefreshDatabase wraps every test in a transaction, so this call is nested: it must not convert or retry.
        $this->expectException(QueryException::class);

        RsvpSubmissionService::transaction(function (): void {
            throw new QueryException('mysql', 'select 1', [], new \PDOException('Lock wait timeout exceeded'));
        });
    }

    public function test_the_last_seat_goes_to_the_first_guest_and_the_second_is_told_why(): void
    {
        $event = $this->event(['guest_limit' => 1]);
        $a = $this->guest($event, 'tok_last_a');
        $b = $this->guest($event, 'tok_last_b');

        $this->token('tok_last_a', 1)->assertSessionHasNoErrors();
        $this->token('tok_last_b', 1)->assertSessionHasErrors(['status' => 'This event has reached its guest limit for confirmed attendees. Please call the host for more information.']);

        $this->assertSame(1, EventAttendance::heldSeats($event->id));
        $this->assertNull($b->rsvp()->first());
        $this->assertNotNull($a->rsvp()->first());
    }
}
