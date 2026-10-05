<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\InvitationTemplate;
use App\Models\User;
use App\Services\InvitationCustomizationService;
use App\Support\InvitationDesignNotices;
use App\Support\InvitationLayoutVariant;
use App\Support\InvitationMediaHealth;
use App\Support\InvitationTemplateNotices;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan: plans/invitation-page-edge-cases.md. Phase 1 — an event that was never published shows no status
 * page: deleted, cancelled or paused, it is a plain 404 and its name never appears.
 */
class InvitationPageEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function event(bool $published, array $overrides = []): Event
    {
        return Event::factory()->for(User::factory()->create())->create(array_merge([
            'name' => 'Secret Draft Party',
            'slug' => 'secret-draft',
            'is_published' => $published,
            'event_date' => now()->addMonth()->format('Y-m-d'),
            'rsvp_deadline' => null,
        ], $overrides));
    }

    /** @return array<string, array{0: callable(Event): void}> */
    public static function lifecycleStates(): array
    {
        return [
            'deleted' => [fn (Event $e) => $e->delete()],
            'cancelled' => [fn (Event $e) => $e->forceFill(['cancelled_at' => now()])->save()],
            'paused' => [fn (Event $e) => $e->forceFill(['invitation_paused_at' => now()])->save()],
        ];
    }

    /**
     * @dataProvider lifecycleStates
     */
    public function test_a_never_published_event_is_a_plain_404_on_every_page(callable $apply): void
    {
        $event = $this->event(published: false);
        $apply($event);

        $this->get(route('events.public', $event->slug))->assertNotFound()->assertDontSee('Secret Draft Party');
        $this->get(route('events.public.ics', $event->slug))->assertNotFound();
        $this->get(route('rsvp.open.show', $event->slug))->assertNotFound()->assertDontSee('Secret Draft Party');
        $this->getJson("/api/v1/events/{$event->slug}")->assertNotFound();
    }

    /**
     * @dataProvider lifecycleStates
     */
    public function test_a_published_event_keeps_its_status_page(callable $apply): void
    {
        $event = $this->event(published: true);
        $apply($event);

        $this->get(route('events.public', $event->slug))
            ->assertOk()
            ->assertSee(match (true) {
                $event->trashed() => 'Invitation no longer available',
                $event->isCancelled() => 'Event cancelled',
                default => 'Invitation unavailable',
            }, escape: false);
    }

    public function test_a_published_deleted_event_still_reports_gone_in_the_api(): void
    {
        $event = $this->event(published: true);
        $event->delete();

        $this->getJson("/api/v1/events/{$event->slug}")->assertOk()->assertJsonPath('status', 'gone');
    }

    // ── Phase 2: "ended" runs on the venue clock ─────────────────────────────

    /** Fake the clock with a UTC instant, from a venue wall-clock time (Africa/Lusaka, UTC+2). */
    private function atVenue(string $venueTime): void
    {
        Carbon::setTestNow(Carbon::parse($venueTime, config('events.timezone'))->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_venue_today_is_the_lusaka_date_not_the_utc_date(): void
    {
        $this->atVenue('2026-11-21 00:30:00'); // 22:30 UTC on the 20th

        $this->assertSame('2026-11-20', now()->toDateString(), 'the app clock is still on the 20th');
        $this->assertSame('2026-11-21', Event::venueToday()->toDateString());
    }

    public function test_an_event_is_live_until_midnight_venue_time_and_ended_after(): void
    {
        $event = $this->event(published: true, overrides: ['event_date' => '2026-11-20']);

        $this->atVenue('2026-11-20 23:59:00');
        $this->assertFalse($event->fresh()->isLocked());
        $this->get(route('events.public', $event->slug))->assertOk()->assertDontSee('Event has ended');

        $this->atVenue('2026-11-21 00:00:00'); // 22:00 UTC: the app clock is still on the 20th
        $this->assertTrue($event->fresh()->isLocked());
        $this->get(route('events.public', $event->slug))->assertOk()->assertSee('Event has ended');
    }

    public function test_upcoming_and_reviewable_flip_at_the_same_instant_as_ended(): void
    {
        $event = $this->event(published: true, overrides: ['event_date' => '2026-11-20']);

        $this->atVenue('2026-11-20 23:59:00');
        $this->assertTrue(Event::query()->upcoming()->whereKey($event->id)->exists());
        $this->assertFalse($event->fresh()->isReviewable());

        $this->atVenue('2026-11-21 00:00:00');
        $this->assertFalse(Event::query()->upcoming()->whereKey($event->id)->exists());
        $this->assertTrue($event->fresh()->isReviewable());
    }

    public function test_the_new_event_date_rule_uses_the_venue_calendar(): void
    {
        $owner = User::factory()->create();
        $this->atVenue('2026-11-21 00:30:00'); // still the 20th in UTC

        $payload = fn (string $date) => [
            'name' => 'Date Rule Party',
            'event_type' => 'birthday',
            'audience' => 'private',
            'product_kind' => 'invitation',
            'host_contact_phone' => '0977123456',
            'event_date' => $date,
            'event_time' => '15:30',
            'venue' => 'Garden Terrace',
        ];

        // The 20th has already passed in Lusaka even though UTC still says the 20th.
        $this->actingAs($owner)->post(route('events.store'), $payload('2026-11-20'))->assertSessionHasErrors('event_date');
        $this->actingAs($owner)->post(route('events.store'), $payload('2026-11-21'))->assertSessionDoesntHaveErrors('event_date');
    }

    // ── Phase 3: a missing template reads as unavailable, never a 500 ────────

    /**
     * Point the event at a template id with no row. SQLite ignores `PRAGMA foreign_keys` inside the test's
     * transaction, so the constraint is deferred instead; the transaction is rolled back, never committed.
     */
    private static function danglingTemplate(Event $event): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('events')->where('id', $event->id)->update(['invitation_template_id' => 999999]);
    }

    /** @return array<string, array{0: callable(Event): void}> */
    public static function missingTemplateStates(): array
    {
        return [
            'no template id' => [fn (Event $e) => $e->forceFill(['invitation_template_id' => null])->save()],
            'id with no row' => [function (Event $e): void {
                self::danglingTemplate($e);

            }],
            'id with no row and an empty catalogue' => [function (Event $e): void {
                self::danglingTemplate($e);
                InvitationTemplate::query()->delete();

            }],
        ];
    }

    /**
     * @dataProvider missingTemplateStates
     */
    public function test_a_missing_template_is_unavailable_on_every_guest_page(callable $break): void
    {
        $event = $this->event(published: true);
        Guest::factory()->for($event)->create(['invitation_token' => 'tok_no_layout']);
        $break($event);

        $this->get(route('events.public', $event->slug))->assertOk()->assertSee('Invitation unavailable', escape: false);
        $this->get(route('rsvp.open.show', $event->slug))->assertOk()->assertSee('Invitation unavailable', escape: false);
        $this->get(route('rsvp.token.show', 'tok_no_layout'))->assertOk()->assertSee('Invitation unavailable', escape: false);
        $this->getJson("/api/v1/events/{$event->slug}")->assertOk()->assertJsonPath('status', 'unavailable');
        $this->get(route('events.public.ics', $event->slug))->assertNotFound();
        $this->assertSame(0, $event->fresh()->invitation_views_count);
    }

    public function test_a_retired_template_keeps_rendering_on_a_live_event(): void
    {
        $event = $this->event(published: true);
        InvitationTemplate::query()->whereKey($event->invitation_template_id)->update(['is_active' => false]);

        $this->get(route('events.public', $event->slug))->assertOk()->assertDontSee('Invitation unavailable');
        $this->get(route('rsvp.open.show', $event->slug))->assertOk()->assertDontSee('Invitation unavailable');
    }

    public function test_a_dangling_template_id_does_not_borrow_another_template(): void
    {
        $event = $this->event(published: true);
        self::danglingTemplate($event);

        $this->assertTrue(InvitationTemplate::query()->where('is_active', true)->exists(), 'a template exists to be borrowed');
        $this->get(route('events.public', $event->slug))->assertSee('Invitation unavailable', escape: false);
    }

    public function test_the_host_is_told_when_the_layout_was_removed_and_cannot_publish(): void
    {
        $event = $this->event(published: false);
        self::danglingTemplate($event);

        $event->refresh();

        $notices = InvitationTemplateNotices::for($event);

        $this->assertCount(1, $notices);
        $this->assertStringContainsString('was removed', $notices[0]['message']);
        $this->assertNotNull($event->invitationTemplatePublishBlocker());
    }

    // ── Phase 4: missing media is hidden from guests and reported to the host ─

    private function eventWithMedia(array $media, array $effects = [], array $overrides = []): Event
    {
        Storage::fake('public');
        $event = $this->event(published: true, overrides: $overrides);
        $event->forceFill(['invitation_customization' => ['media' => $media, 'effects' => $effects]])->save();

        return $event->fresh();
    }

    public function test_merge_for_guests_drops_missing_files_but_keeps_the_host_view_whole(): void
    {
        $event = $this->eventWithMedia(['gallery' => ['inv/there.webp', 'inv/gone.webp'], 'hero_portrait' => 'inv/gone-hero.webp']);
        Storage::disk('public')->put('inv/there.webp', 'x');
        $service = app(InvitationCustomizationService::class);

        $guest = $service->merge($event, hideMissingMedia: true);
        $host = $service->merge($event);

        $this->assertSame(['inv/there.webp'], $guest['media']['gallery']);
        $this->assertNull($guest['media']['hero_portrait']);
        $this->assertSame(['inv/there.webp', 'inv/gone.webp'], $host['media']['gallery'], 'the editor still shows what is saved');
        $this->assertSame('inv/gone-hero.webp', $host['media']['hero_portrait']);
    }

    public function test_every_gallery_file_gone_leaves_an_empty_gallery(): void
    {
        $event = $this->eventWithMedia(['gallery' => ['inv/a.webp', 'inv/b.webp']]);

        $this->assertSame([], app(InvitationCustomizationService::class)->merge($event, hideMissingMedia: true)['media']['gallery']);
    }

    public function test_the_public_page_does_not_print_a_missing_gallery_file(): void
    {
        $event = $this->eventWithMedia(['gallery' => ['inv/there.webp', 'inv/gone.webp']]);
        Storage::disk('public')->put('inv/there.webp', 'x');

        $this->get(route('events.public', $event->slug))->assertOk()->assertDontSee('inv/gone.webp');
    }

    public function test_missing_video_and_audio_files_are_dropped_but_youtube_is_kept(): void
    {
        $file = $this->eventWithMedia([], ['video_background' => 'inv/video.mp4', 'audio_track' => 'inv/song.mp3']);
        $merged = app(InvitationCustomizationService::class)->merge($file, hideMissingMedia: true);
        $this->assertNull($merged['effects']['video_background']);
        $this->assertNull($merged['effects']['audio_track']);

        $youtube = $this->eventWithMedia([], ['video_background' => 'youtube:dQw4w9WgXcQ'], ['slug' => 'yt-event']);
        $this->assertSame('youtube:dQw4w9WgXcQ', app(InvitationCustomizationService::class)->merge($youtube, hideMissingMedia: true)['effects']['video_background']);
    }

    public function test_a_positional_layout_keeps_the_blank_slot(): void
    {
        Storage::fake('public');
        foreach (['a', 'c', 'd'] as $n) {
            Storage::disk('public')->put("inv/$n.webp", 'x');
        }
        $invitation = [
            'layout_variant' => InvitationLayoutVariant::BEAUTY_FOR_ASHES,
            'media' => ['gallery' => [], 'hero_portrait' => null, 'couple_photos' => ['inv/a.webp', 'inv/gone.webp', 'inv/c.webp', 'inv/d.webp']],
            'effects' => [],
        ];

        $this->assertSame(['inv/a.webp', '', 'inv/c.webp', 'inv/d.webp'], InvitationMediaHealth::hide($invitation)['media']['couple_photos']);
    }

    public function test_absolute_urls_are_never_flagged(): void
    {
        $event = $this->eventWithMedia(['gallery' => ['https://images.example.com/a.jpg', '/images/b.jpg']]);

        $this->assertSame([], InvitationMediaHealth::missing($event));
    }

    public function test_the_host_is_told_what_is_missing_and_it_clears_after_a_reupload(): void
    {
        $event = $this->eventWithMedia(['gallery' => ['inv/a.webp']], ['audio_track' => 'inv/song.mp3'], ['cover_image' => 'events/cover-gone.webp']);

        $slots = array_column(InvitationMediaHealth::missing($event), 'slot');
        $this->assertEqualsCanonicalizing(['cover', 'gallery', 'audio'], $slots);

        $notice = collect(InvitationDesignNotices::for($event))->first(fn ($n) => str_contains($n['message'], 'can no longer be found'));
        $this->assertNotNull($notice);
        $this->assertStringContainsString('3 files', $notice['message']);

        foreach (['events/cover-gone.webp', 'inv/a.webp', 'inv/song.mp3'] as $path) {
            Storage::disk('public')->put($path, 'x');
        }
        $this->assertSame([], InvitationMediaHealth::missing($event->fresh()));
    }

    public function test_a_missing_cover_still_falls_back_and_is_reported(): void
    {
        $event = $this->eventWithMedia([], [], ['cover_image' => 'events/cover-gone.webp']);

        $this->assertStringEndsWith('images/default-event.png', $event->cover_image_url);
        $this->assertSame(['cover'], array_column(InvitationMediaHealth::missing($event), 'slot'));
    }

    public function test_the_host_api_reports_media_issues_and_is_null_when_healthy(): void
    {
        $event = $this->eventWithMedia(['gallery' => ['inv/a.webp']]);
        Sanctum::actingAs($event->user);

        $this->getJson(route('api.v1.host.events.show', $event))
            ->assertOk()
            ->assertJsonPath('media_issues.count', 1)
            ->assertJsonPath('media_issues.items.0.slot', 'gallery');

        Storage::disk('public')->put('inv/a.webp', 'x');
        $this->getJson(route('api.v1.host.events.show', $event))->assertOk()->assertJsonPath('media_issues', null);
    }
}
