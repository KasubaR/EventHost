<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use App\Models\User;
use App\Notifications\NewRsvpReceivedNotification;
use App\Services\RsvpSubmissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * plans/rsvp-status-changes.md Phase 4: a history of how each guest's answer changed, and a host alert that says
 * "changed from X to Y" instead of "responded Y".
 */
class RsvpChangeLogTest extends TestCase
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
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-10-05 18:00:00',
            'allow_plus_one' => true,
            'guest_limit' => null,
            'is_public' => false,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', config('events.timezone'))->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function guest(): Guest
    {
        return Guest::factory()->for($this->event)->create(['plus_one_allowed' => true]);
    }

    private function answer(Guest $guest, string $status, int $seats = 1)
    {
        return $this->post(route('rsvp.token.store', $guest->invitation_token), [
            'status' => $status,
            'attendee_count' => $status === 'accepted' ? $seats : 0,
        ]);
    }

    // ---------------------------------------------------------------- what gets logged

    public function test_a_first_answer_is_logged_with_no_previous_answer(): void
    {
        $guest = $this->guest();

        $this->answer($guest, 'accepted', 2)->assertSessionHasNoErrors();

        $change = RsvpChange::query()->where('guest_id', $guest->id)->sole();
        $this->assertNull($change->from_status);
        $this->assertNull($change->from_seats);
        $this->assertSame(RsvpStatus::Accepted, $change->to_status);
        $this->assertSame(2, $change->to_seats);
        $this->assertSame(RsvpChange::CHANNEL_WEB_TOKEN, $change->channel);
        $this->assertNull($change->actor_user_id);
        $this->assertSame($guest->rsvp->id, $change->rsvp_id);
        $this->assertSame($this->event->id, $change->event_id);
    }

    public function test_every_change_of_answer_is_a_row_in_order(): void
    {
        $guest = $this->guest();

        $this->answer($guest, 'accepted', 2);
        $this->answer($guest, 'declined');
        $this->answer($guest, 'maybe');
        $this->answer($guest, 'accepted', 1);

        $rows = RsvpChange::query()->where('guest_id', $guest->id)->orderBy('id')->get();
        $this->assertSame(
            ['No answer to Attending (2 seats)', 'Attending (2 seats) to Not Attending', 'Not Attending to Maybe Attending', 'Maybe Attending to Attending (1 seat)'],
            $rows->map->describe()->all()
        );
    }

    public function test_a_change_of_seats_alone_is_logged(): void
    {
        $guest = $this->guest();
        $this->answer($guest, 'accepted', 2);

        $this->answer($guest, 'accepted', 1);

        $this->assertSame('Attending (2 seats) to Attending (1 seat)', RsvpChange::query()->where('guest_id', $guest->id)->orderByDesc('id')->first()->describe());
    }

    public function test_the_same_answer_again_or_a_new_message_is_not_a_change_of_answer(): void
    {
        $guest = $this->guest();
        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1, 'message' => 'Hello']);

        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1, 'message' => 'Hello']);
        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1, 'message' => 'Running late']);

        $this->assertSame(1, RsvpChange::query()->where('guest_id', $guest->id)->count());
    }

    public function test_a_refused_submit_leaves_no_history_row(): void
    {
        $guest = $this->guest();
        $this->answer($guest, 'declined');
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00', config('events.timezone'))->utc());

        $this->answer($guest, 'accepted')->assertSessionHas('rsvp_closed');

        $this->assertSame(1, RsvpChange::query()->where('guest_id', $guest->id)->count());
    }

    // ---------------------------------------------------------------- channels and actor

    public function test_each_channel_is_recorded(): void
    {
        $service = app(RsvpSubmissionService::class);

        foreach ([RsvpChange::CHANNEL_WHATSAPP, RsvpChange::CHANNEL_API, RsvpChange::CHANNEL_GROUP] as $channel) {
            $guest = $this->guest();
            $service->submit($this->event, $guest, ['status' => RsvpStatus::Accepted, 'attendee_count' => 1, 'message' => null], channel: $channel);

            $this->assertSame($channel, RsvpChange::query()->where('guest_id', $guest->id)->value('channel'));
        }
    }

    public function test_the_open_form_and_the_api_and_the_group_link_log_their_own_channel(): void
    {
        $this->event->update(['guest_limit' => null]);

        $this->post(route('rsvp.open.store', ['slug' => $this->event->slug]), [
            'name' => 'Open Guest', 'email' => 'open@example.test', 'phone' => '0970000010', 'status' => 'accepted', 'attendee_count' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(RsvpChange::CHANNEL_WEB_OPEN, RsvpChange::query()->whereHas('guest', fn ($q) => $q->where('email', 'open@example.test'))->value('channel'));

        $apiGuest = $this->guest();
        $this->postJson(route('api.v1.rsvp.token.store', $apiGuest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1])->assertOk();
        $this->assertSame(RsvpChange::CHANNEL_API, RsvpChange::query()->where('guest_id', $apiGuest->id)->value('channel'));

        $group = GuestGroup::factory()->for($this->event)->create();
        $group->enableLink(3);
        $group = $group->fresh();
        $this->post(route('group-rsvp.store', ['token' => $group->rsvp_token]), [
            'name' => 'Group Guest', 'email' => 'group@example.test', 'phone' => '0970000011', 'attendee_count' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(RsvpChange::CHANNEL_GROUP, RsvpChange::query()->whereHas('guest', fn ($q) => $q->where('email', 'group@example.test'))->value('channel'));
    }

    public function test_a_host_action_records_the_host_as_the_actor(): void
    {
        $guest = $this->guest();

        app(RsvpSubmissionService::class)->submit(
            $this->event,
            $guest,
            ['status' => RsvpStatus::Declined, 'attendee_count' => 0, 'message' => null],
            enforceDeadline: false,
            channel: RsvpChange::CHANNEL_HOST,
            actorUserId: $this->host->id,
        );

        $change = RsvpChange::query()->where('guest_id', $guest->id)->sole();
        $this->assertSame($this->host->id, $change->actor_user_id);
        $this->assertStringContainsString('Host ('.$this->host->name.')', $change->channelLabel());
    }

    // ---------------------------------------------------------------- the host's alert

    public function test_the_host_is_told_what_changed_not_just_what_was_said(): void
    {
        $guest = $this->guest();
        $this->answer($guest, 'accepted', 2);
        $this->answer($guest, 'declined');

        $sent = Notification::sent($this->host, NewRsvpReceivedNotification::class);
        $this->assertCount(2, $sent);
        $this->assertNull($sent[0]->previous, 'a first answer has nothing before it');
        $this->assertSame(['status' => 'accepted', 'seats' => 2], $sent[1]->previous);

        $mail = $sent[1]->toMail($this->host);
        $text = implode("\n", array_merge([$mail->subject], $mail->introLines));
        $this->assertStringContainsString('RSVP changed by '.$guest->name, $text);
        $this->assertStringContainsString('Was: Attending (2 seats). Now: Not Attending.', $text);

        $first = $sent[0]->toMail($this->host);
        $this->assertStringContainsString('New RSVP from', $first->subject);
    }

    // ---------------------------------------------------------------- where the host sees it

    public function test_the_guest_page_shows_the_history_newest_first(): void
    {
        $guest = $this->guest();
        $this->answer($guest, 'accepted', 2);
        $this->answer($guest, 'declined');

        $html = $this->actingAs($this->host)->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))->assertOk()->getContent();

        $this->assertStringContainsString('Response history', $html);
        $this->assertStringContainsString('Attending (2 seats) to Not Attending', $html);
        $this->assertStringContainsString('(Guest, personal link)', $html);
        $this->assertLessThan(strpos($html, 'No answer to Attending (2 seats)'), strpos($html, 'Attending (2 seats) to Not Attending'));
    }

    public function test_the_guest_page_says_so_when_nothing_changed_yet(): void
    {
        $guest = $this->guest();

        $this->actingAs($this->host)->get(route('events.guests.edit', ['event' => $this->event, 'guest' => $guest]))
            ->assertOk()
            ->assertSee("No changes to this guest's answer have been recorded.", false);
    }

    public function test_the_csv_export_has_a_last_changed_column(): void
    {
        $guest = $this->guest();
        $untouched = $this->guest();
        $this->answer($guest, 'accepted', 1);

        $csv = $this->actingAs($this->host)->get(route('events.guests.export', $this->event))->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));

        $this->assertSame('Response Last Changed', end($rows[0]));
        $byName = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertSame('2026-10-01 07:00', $byName[$guest->name][count($rows[0]) - 1]);
        $this->assertSame('', $byName[$untouched->name][count($rows[0]) - 1]);
    }

    public function test_the_history_goes_with_the_guest(): void
    {
        $guest = $this->guest();
        $this->answer($guest, 'accepted');
        $this->assertSame(1, RsvpChange::query()->count());

        $guest->delete();

        $this->assertSame(0, RsvpChange::query()->count());
        $this->assertSame(0, Rsvp::query()->count());
    }
}
