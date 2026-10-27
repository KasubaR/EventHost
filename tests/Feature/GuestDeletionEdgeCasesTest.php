<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\GuestRemoval;
use App\Models\NotificationLog;
use App\Models\Rsvp;
use App\Models\RsvpChange;
use App\Models\User;
use App\Notifications\EventUpdatedNotification;
use App\Notifications\GuestEventReminderNotification;
use App\Notifications\NewRsvpReceivedNotification;
use App\Notifications\PlusOneRemovedNotification;
use App\Notifications\RsvpAwaitingApprovalNotification;
use App\Notifications\RsvpConfirmationNotification;
use App\Notifications\RsvpExtraSeatPendingNotification;
use App\Notifications\RsvpRejectedNotification;
use App\Notifications\RsvpReminderNotification;
use App\Services\CommunicationService;
use App\Services\GuestDeleter;
use App\Services\GuestPassFileCache;
use App\Support\GuestPhone;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * plans/guest-deletion-gaps.md, Phases 0 and 1: what happens to a guest's RSVP, history, queued mail and old links
 * when the host deletes them. The tests marked "today" pin current behaviour that later phases deliberately change.
 */
class GuestDeletionEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->pro()->create();
        $this->event = Event::factory()->for($this->host)->published()->create([
            'event_date' => '2026-11-20',
            'event_time' => '15:00:00',
            'rsvp_deadline' => '2026-11-10 18:00:00',
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

    private function guest(array $attributes = []): Guest
    {
        return Guest::factory()->for($this->event)->create($attributes + ['plus_one_allowed' => true]);
    }

    private function acceptedGuest(int $seats = 1): Guest
    {
        $guest = $this->guest();
        $this->post(route('rsvp.token.store', $guest->invitation_token), [
            'status' => 'accepted',
            'attendee_count' => $seats,
        ])->assertSessionHasNoErrors();

        return $guest->fresh(['rsvp']);
    }

    // ---------------------------------------------------------------- 1. deleted after RSVP

    public function test_deleting_an_accepted_guest_frees_their_seats_today(): void
    {
        Notification::fake();
        $group = GuestGroup::factory()->for($this->event)->create(['seat_limit' => 3]);
        $guest = $this->guest(['guest_group_id' => $group->id]);
        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 2]);
        $this->assertSame(1, $group->fresh()->seatsRemaining());

        $this->actingAs($this->host)
            ->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $guest->id]))
            ->assertRedirect();

        $this->assertSame(3, $group->fresh()->seatsRemaining());
        $this->assertDatabaseMissing('rsvps', ['guest_id' => $guest->id]);
    }

    public function test_deleting_a_guest_also_deletes_their_answer_history_today(): void
    {
        Notification::fake();
        $guest = $this->acceptedGuest();
        $this->assertSame(1, RsvpChange::query()->where('guest_id', $guest->id)->count());

        $this->actingAs($this->host)
            ->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $guest->id]));

        // Phase 3 keeps a tombstone; the cascade itself stays, the host just stops losing the fact it happened.
        $this->assertSame(0, RsvpChange::query()->where('guest_id', $guest->id)->count());
    }

    // ---------------------------------------------------------------- 2. deleted after the invitation was sent

    public function test_the_delivery_log_outlives_the_guest_with_no_guest_id_today(): void
    {
        Notification::fake();
        $guest = $this->guest(['email' => 'sent@example.test']);
        app(CommunicationService::class)->sendPlusOneRemoved($this->event, $guest);
        $log = NotificationLog::query()->where('guest_id', $guest->id)->sole();

        $guest->delete();

        $this->assertNull($log->fresh()->guest_id);
        $this->assertSame($this->event->id, $log->fresh()->event_id);
    }

    // ---------------------------------------------------------------- 3. deleted while a notification is queued

    /**
     * @return array<string, array{0: callable(Event, Guest, Rsvp): object}>
     */
    public static function guestNotifications(): array
    {
        return [
            'confirmation' => [fn ($e, $g, $r) => new RsvpConfirmationNotification($e, $g, $r)],
            'reminder' => [fn ($e, $g, $r) => new RsvpReminderNotification($e, $g, 3)],
            'event updated' => [fn ($e, $g, $r) => new EventUpdatedNotification($e, $g, 'New venue')],
            'event reminder' => [fn ($e, $g, $r) => new GuestEventReminderNotification($e, $g, '7')],
            'plus-one removed' => [fn ($e, $g, $r) => new PlusOneRemovedNotification($e, $g)],
            'rejected' => [fn ($e, $g, $r) => new RsvpRejectedNotification($e, $g, $r, 'Sorry')],
            'extra seat pending' => [fn ($e, $g, $r) => new RsvpExtraSeatPendingNotification($e, $g, $r)],
            'host: new rsvp' => [fn ($e, $g, $r) => new NewRsvpReceivedNotification($e, $g, $r)],
            'host: awaiting approval' => [fn ($e, $g, $r) => new RsvpAwaitingApprovalNotification($e, $g, $r)],
        ];
    }

    #[DataProvider('guestNotifications')]
    public function test_a_queued_notification_for_a_deleted_guest_restores_and_declines_to_send(callable $make): void
    {
        Notification::fake();
        $guest = $this->acceptedGuest();
        $queued = serialize($make($this->event, $guest, $guest->rsvp));

        $guest->delete();

        $restored = unserialize($queued);
        $this->assertFalse($restored->shouldSend(new AnonymousNotifiable, 'mail'));
    }

    #[DataProvider('guestNotifications')]
    public function test_a_queued_notification_for_a_live_guest_still_sends(callable $make): void
    {
        Notification::fake();
        $guest = $this->acceptedGuest();
        $made = $make($this->event, $guest, $guest->rsvp);

        // An RSVP reminder is for someone who has not answered; anyone who has is dropped at send time (EmailSendTimeChecksTest).
        if ($made instanceof RsvpReminderNotification) {
            $guest = $this->guest();
            $made = $make($this->event, $guest, null);
        }

        $restored = unserialize(serialize($made));

        $this->assertTrue($restored->shouldSend(new AnonymousNotifiable, 'mail'));
        $this->assertSame($guest->id, $restored->guest->id);
    }

    public function test_the_worker_drops_mail_for_a_deleted_guest_without_a_failed_job(): void
    {
        config(['queue.default' => 'database']);
        $guest = $this->acceptedGuest();
        DB::table('jobs')->delete(); // the RSVP above queued its own mail

        Notification::route('mail', 'gone@example.test')
            ->notify(new RsvpConfirmationNotification($this->event, $guest, $guest->rsvp));
        $this->assertSame(1, DB::table('jobs')->count());

        $guest->delete();
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $this->assertSame(0, DB::table('jobs')->count(), 'the job should be consumed');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'a deleted guest must not create a failed job');
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_the_worker_still_sends_mail_for_a_live_guest(): void
    {
        config(['queue.default' => 'database']);
        $guest = $this->guest(['email' => 'live@example.test']);

        Notification::route('mail', 'live@example.test')
            ->notify(new EventUpdatedNotification($this->event, $guest, 'New venue'));
        Artisan::call('queue:work', ['--once' => true, '--queue' => 'default']);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertCount(1, $this->sentMessages());
    }

    /**
     * @return array<int, mixed>
     */
    private function sentMessages(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    // ---------------------------------------------------------------- 4. deleted guest uses their old link

    public function test_a_removed_guests_old_link_says_it_was_removed(): void
    {
        Notification::fake();
        $this->event->update(['host_contact_phone' => '0977000111']);
        $guest = $this->guest(['name' => 'Chipo Mwansa']);
        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 1]);
        $token = $guest->invitation_token;
        app(GuestDeleter::class)->delete($this->event, $guest);

        foreach (['rsvp.token.show', 'rsvp.token.thanks', 'rsvp.token.pass', 'rsvp.token.pass-download'] as $name) {
            $this->get(route($name, $token))
                ->assertStatus(410)
                ->assertSee('This invitation is no longer active')
                ->assertSee($this->event->name)
                ->assertSee('0977000111')
                ->assertDontSee('Chipo Mwansa');
        }
        $this->post(route('rsvp.token.store', $token), ['status' => 'declined', 'attendee_count' => 0])
            ->assertStatus(410)
            ->assertSee('no longer active');
    }

    public function test_an_unknown_link_keeps_the_ordinary_not_found_page(): void
    {
        $token = str_repeat('z', 48);

        $this->get(route('rsvp.token.show', $token))
            ->assertNotFound()
            ->assertDontSee('no longer active');
    }

    public function test_a_removed_guests_image_routes_stay_a_plain_404(): void
    {
        $guest = $this->guest();
        $token = $guest->invitation_token;
        app(GuestDeleter::class)->delete($this->event, $guest);

        $this->get(route('rsvp.token.pass-image', $token))->assertNotFound();
        $this->get(route('rsvp.token.entry-pass-png', $token))->assertNotFound();
    }

    public function test_the_api_says_removed_with_a_stable_code_and_keeps_the_404(): void
    {
        $guest = $this->guest();
        $token = $guest->invitation_token;
        app(GuestDeleter::class)->delete($this->event, $guest);

        $this->getJson("/api/v1/rsvp/{$token}")
            ->assertNotFound()
            ->assertJsonPath('code', 'invitation_removed');
        $this->postJson("/api/v1/rsvp/{$token}", ['status' => 'declined'])
            ->assertNotFound()
            ->assertJsonPath('code', 'invitation_removed');
        $this->getJson('/api/v1/rsvp/'.str_repeat('z', 48))
            ->assertNotFound()
            ->assertJsonMissingPath('code');
    }

    public function test_door_scanners_are_told_a_removed_guest_was_removed(): void
    {
        $guest = $this->guest();
        $token = $guest->invitation_token;
        app(GuestDeleter::class)->delete($this->event, $guest);
        $link = EventStaffLink::factory()->for($this->event)->create();

        $this->actingAs($this->host)
            ->postJson(route('events.checkin.confirm-token', ['event' => $this->event, 'token' => $token]))
            ->assertNotFound()->assertJsonPath('code', 'guest_removed');
        $this->postJson(route('checkin.public.confirm-token', ['staffToken' => $link->token, 'token' => $token]))
            ->assertNotFound()->assertJsonPath('code', 'guest_removed');

        Sanctum::actingAs($this->host);
        $this->postJson("/api/v1/host/events/{$this->event->id}/checkin/{$token}")
            ->assertNotFound()->assertJsonPath('code', 'guest_removed');
    }

    public function test_a_scanner_for_another_event_is_not_told_the_guest_was_removed(): void
    {
        $guest = $this->guest();
        $token = $guest->invitation_token;
        app(GuestDeleter::class)->delete($this->event, $guest);
        $other = Event::factory()->for($this->host)->published()->create(['is_public' => false]);

        $this->actingAs($this->host)
            ->postJson(route('events.checkin.confirm-token', ['event' => $other, 'token' => $token]))
            ->assertNotFound()
            ->assertJsonMissingPath('code')
            ->assertJsonPath('message', 'No matching invitation for this event.');
    }

    public function test_an_unknown_qr_still_gets_the_plain_no_match_message(): void
    {
        $this->actingAs($this->host)
            ->postJson(route('events.checkin.confirm-token', ['event' => $this->event, 'token' => str_repeat('q', 48)]))
            ->assertNotFound()
            ->assertJsonPath('message', 'No matching invitation for this event.');
    }

    public function test_a_deleted_guest_cannot_bring_themselves_back_through_their_old_link(): void
    {
        Notification::fake();
        $guest = $this->acceptedGuest();
        $token = $guest->invitation_token;
        $guest->delete();

        $this->post(route('rsvp.token.store', $token), ['status' => 'accepted', 'attendee_count' => 1]);

        $this->assertDatabaseMissing('guests', ['invitation_token' => $token]);
        $this->assertDatabaseCount('rsvps', 0);
    }

    // ---------------------------------------------------------------- Phase 2: one delete path

    private function passFile(Guest $guest): string
    {
        $path = app(GuestPassFileCache::class)->directory(GuestPassFileCache::PDF, (string) $guest->invitation_token).'/card.pdf';
        Storage::disk('local')->put($path, 'x');

        return $path;
    }

    public function test_the_deleter_reports_what_was_lost(): void
    {
        Notification::fake();
        $accepted = $this->acceptedGuest(2);
        $unanswered = $this->guest(['invitation_sent' => true]);
        $plain = $this->guest();

        $result = app(GuestDeleter::class)->deleteMany($this->event, [$accepted->id, $unanswered->id, $plain->id]);

        $this->assertSame(['deleted' => 3, 'seats_freed' => 2, 'answered' => 1, 'invitation_sent' => 1], $result);
        $this->assertSame(0, $this->event->guests()->count());
    }

    public function test_the_deleter_never_touches_another_events_guests(): void
    {
        $other = Event::factory()->for($this->host)->published()->create();
        $stranger = Guest::factory()->for($other)->create();

        $result = app(GuestDeleter::class)->deleteMany($this->event, [$stranger->id]);

        $this->assertSame(0, $result['deleted']);
        $this->assertNotNull(Guest::find($stranger->id));
    }

    public function test_deleting_removes_the_cached_pass_files(): void
    {
        Storage::fake('local');
        $guest = $this->guest();
        $kept = $this->guest();
        $path = $this->passFile($guest);
        $keptPath = $this->passFile($kept);

        app(GuestDeleter::class)->delete($this->event, $guest);

        Storage::disk('local')->assertMissing($path);
        Storage::disk('local')->assertExists($keptPath);
    }

    public function test_every_delete_path_goes_through_the_deleter_and_clears_pass_files(): void
    {
        Storage::fake('local');
        Notification::fake();
        Sanctum::actingAs($this->host);

        $web = $this->guest();
        $api = $this->guest();
        $webBulk = $this->guest();
        $apiBulk = $this->guest();
        $files = [$web->id => $this->passFile($web), $api->id => $this->passFile($api), $webBulk->id => $this->passFile($webBulk), $apiBulk->id => $this->passFile($apiBulk)];

        $this->actingAs($this->host)
            ->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $web->id]))->assertRedirect();
        $this->deleteJson("/api/v1/host/events/{$this->event->id}/guests/{$api->id}")->assertNoContent();
        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'delete', 'guest_ids' => [$webBulk->id]])->assertRedirect();
        $this->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'delete', 'guest_ids' => [$apiBulk->id]])
            ->assertOk()->assertJsonPath('affected_count', 1);

        $this->assertSame(0, $this->event->guests()->count());
        foreach ($files as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_a_bulk_delete_frees_the_seats_and_leaves_unselected_guests(): void
    {
        Notification::fake();
        $group = GuestGroup::factory()->for($this->event)->create(['seat_limit' => 4]);
        $a = $this->guest(['guest_group_id' => $group->id]);
        $b = $this->guest(['guest_group_id' => $group->id]);
        foreach ([$a, $b] as $guest) {
            $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 2]);
        }
        $this->assertSame(0, $group->fresh()->seatsRemaining());

        $this->actingAs($this->host)
            ->post(route('events.guests.bulk', $this->event), ['action' => 'delete', 'guest_ids' => [$a->id]])->assertRedirect();

        $this->assertSame(2, $group->fresh()->seatsRemaining());
        $this->assertNotNull(Guest::find($b->id));
    }

    // ---------------------------------------------------------------- Phase 3: tombstones

    public function test_a_deleted_guest_leaves_a_tombstone(): void
    {
        Notification::fake();
        $guest = $this->guest(['name' => 'Thandi Banda', 'email' => 'Thandi@Example.test', 'phone' => '0971234567', 'invitation_sent' => true]);
        $this->post(route('rsvp.token.store', $guest->invitation_token), ['status' => 'accepted', 'attendee_count' => 2]);
        $token = $guest->invitation_token;

        $this->actingAs($this->host)
            ->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $guest->id]));

        $removal = GuestRemoval::query()->sole();
        $this->assertSame($this->event->id, $removal->event_id);
        $this->assertSame('Thandi Banda', $removal->name);
        $this->assertSame('thandi@example.test', $removal->email);
        $this->assertSame(GuestPhone::key('0971234567'), $removal->phone_key);
        $this->assertSame('accepted', $removal->last_status);
        $this->assertSame(2, $removal->seats_freed);
        $this->assertTrue($removal->invitation_sent);
        $this->assertSame($this->host->id, $removal->removed_by);
        $this->assertNotNull($removal->removed_at);
        $this->assertSame($removal->id, GuestRemoval::forToken($token)?->id);
    }

    public function test_the_tombstone_never_stores_the_link_itself(): void
    {
        Notification::fake();
        $guest = $this->guest();
        $token = $guest->invitation_token;

        app(GuestDeleter::class)->delete($this->event, $guest);

        $this->assertDatabaseMissing('guest_removals', ['token_hash' => $token]);
        $this->assertSame(64, strlen((string) GuestRemoval::query()->sole()->token_hash));
    }

    public function test_an_unknown_link_has_no_tombstone(): void
    {
        $this->assertNull(GuestRemoval::forToken(str_repeat('a', 48)));
    }

    public function test_a_live_guests_link_has_no_tombstone(): void
    {
        $guest = $this->guest();

        $this->assertNull(GuestRemoval::forToken($guest->invitation_token));
    }

    public function test_every_delete_path_writes_a_tombstone(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->host);
        [$a, $b, $c, $d] = [$this->guest(), $this->guest(), $this->guest(), $this->guest()];

        $this->actingAs($this->host)->delete(route('events.guests.destroy', ['event' => $this->event, 'guest' => $a->id]));
        $this->deleteJson("/api/v1/host/events/{$this->event->id}/guests/{$b->id}");
        $this->actingAs($this->host)->post(route('events.guests.bulk', $this->event), ['action' => 'delete', 'guest_ids' => [$c->id]]);
        $this->postJson("/api/v1/host/events/{$this->event->id}/guests/bulk", ['action' => 'delete', 'guest_ids' => [$d->id]]);

        $this->assertSame(4, GuestRemoval::query()->count());
        $this->assertSame(4, GuestRemoval::query()->where('removed_by', $this->host->id)->count());
    }

    public function test_a_guest_of_another_event_gets_no_tombstone(): void
    {
        $other = Event::factory()->for($this->host)->published()->create();
        $stranger = Guest::factory()->for($other)->create();

        app(GuestDeleter::class)->deleteMany($this->event, [$stranger->id]);

        $this->assertSame(0, GuestRemoval::query()->count());
    }

    public function test_tombstones_go_with_the_event(): void
    {
        $guest = $this->guest();
        app(GuestDeleter::class)->delete($this->event, $guest, $this->host->id);
        $this->assertSame(1, GuestRemoval::query()->count());

        $this->event->forceDelete();

        $this->assertSame(0, GuestRemoval::query()->count());
    }

    public function test_a_tombstone_outlives_the_account_that_removed_the_guest(): void
    {
        $remover = User::factory()->create();
        $guest = $this->guest();
        app(GuestDeleter::class)->delete($this->event, $guest, $remover->id);

        $remover->delete();

        $this->assertNull(GuestRemoval::query()->sole()->removed_by);
    }

    // ---------------------------------------------------------------- Phase 7: the orphan sweep still works

    public function test_the_orphan_sweep_still_clears_a_pass_folder_left_behind_after_a_delete(): void
    {
        Storage::fake('local');
        $guest = $this->guest();
        $token = (string) $guest->invitation_token;
        app(GuestDeleter::class)->delete($this->event, $guest);
        // A render that raced the delete can leave a folder behind; the sweep is the safety net for it.
        $stray = app(GuestPassFileCache::class)->directory(GuestPassFileCache::PDF, $token).'/late.pdf';
        Storage::disk('local')->put($stray, 'x');
        $live = $this->guest();
        $kept = $this->passFile($live);

        $this->artisan('invitation:prune-orphaned-files')->assertSuccessful();

        Storage::disk('local')->assertMissing($stray);
        Storage::disk('local')->assertExists($kept);
    }
}
