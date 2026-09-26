<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\GuestPassFileCache;
use App\Services\GuestPassImageService;
use App\Support\GuestPassCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3 of plans/invitation-pass-card.md: the pass as a full-card PNG.
 */
class GuestPassImageTest extends TestCase
{
    use RefreshDatabase;

    private function attendingGuest(array $eventOverrides = [], array $rsvpOverrides = [], string $token = 'pass-img-token', ?User $owner = null): Guest
    {
        $event = Event::factory()->for($owner ?? User::factory()->pro()->create())->create(array_merge([
            'name' => 'Amy and Joe Wedding',
            'venue' => 'Sunset Gardens',
            'event_date' => now()->addDays(20)->startOfDay(),
        ], $eventOverrides));

        $guest = Guest::factory()->for($event)->create(['name' => 'Chanda Mwila', 'invitation_token' => $token]);
        Rsvp::factory()->for($guest)->create(array_merge([
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 2,
        ], $rsvpOverrides));

        return $guest;
    }

    private function cachedFiles(string $token): array
    {
        return Storage::disk('local')->files(GuestPassFileCache::IMAGE.'/'.$token);
    }

    public function test_it_returns_a_real_png_of_the_full_card(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $response = $this->get(route('rsvp.token.pass-image', $guest->invitation_token));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        $info = getimagesizefromstring($response->getContent());
        $this->assertNotFalse($info, 'Response is not a decodable image.');
        $this->assertSame(1080, $info[0]);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        // The full card is a tall portrait; the bare QR fallback is a square.
        $this->assertGreaterThan($info[0], $info[1]);
    }

    public function test_download_flag_sets_a_filename_named_after_the_event(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass-image', [$guest->invitation_token, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="amy-and-joe-wedding-pass.png"');

        $this->assertNull($this->get(route('rsvp.token.pass-image', $guest->invitation_token))->headers->get('Content-Disposition'));
    }

    public function test_second_request_is_served_from_the_cache(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->assertOk();
        $files = $this->cachedFiles($guest->invitation_token);
        $this->assertCount(1, $files);

        Storage::disk('local')->put($files[0], 'CACHED-SENTINEL');

        $this->assertSame('CACHED-SENTINEL', $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->getContent());
    }

    public function test_editing_the_card_renders_a_fresh_image_and_drops_the_stale_one(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->assertOk();
        $before = $this->cachedFiles($guest->invitation_token);

        $table = EventTable::factory()->for($guest->event)->create(['label' => 'Table 7']);
        $guest->forceFill(['event_table_id' => $table->id])->save();

        $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->assertOk();
        $after = $this->cachedFiles($guest->invitation_token);

        $this->assertCount(1, $after);
        $this->assertNotSame($before, $after);
    }

    public function test_pdf_and_image_share_a_fingerprint_but_not_a_folder(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk();
        $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->assertOk();

        $pdf = Storage::disk('local')->files(GuestPassFileCache::PDF.'/'.$guest->invitation_token);
        $png = $this->cachedFiles($guest->invitation_token);

        $this->assertCount(1, $pdf);
        $this->assertCount(1, $png);
        $this->assertSame(pathinfo($pdf[0], PATHINFO_FILENAME), pathinfo($png[0], PATHINFO_FILENAME));
    }

    public function test_ineligible_guests_get_a_404(): void
    {
        Storage::fake('local');

        $declined = $this->attendingGuest(rsvpOverrides: ['status' => RsvpStatus::Declined], token: 'declined-img-token');
        $this->get(route('rsvp.token.pass-image', $declined->invitation_token))->assertNotFound();

        $basic = $this->attendingGuest(token: 'basic-img-token', owner: User::factory()->create());
        $this->get(route('rsvp.token.pass-image', $basic->invitation_token))->assertNotFound();

        $this->get(route('rsvp.token.pass-image', 'no-such-token-at-all'))->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('guest-pass-images'));
    }

    public function test_it_copes_with_long_names_states_and_missing_optional_fields(): void
    {
        Storage::fake('local');
        $service = app(GuestPassImageService::class);

        $long = $this->attendingGuest(
            eventOverrides: [
                'name' => 'The Extraordinarily Long-Named Annual Charity Gala Dinner and Silent Auction in Aid of Community Education Programmes Across Lusaka Province',
                'venue' => 'Sunset Gardens Conference and Events Centre, Plot 4471 Great East Road, Lusaka, Zambia, Southern Africa',
            ],
            token: 'long-img-token',
        );
        $long->forceFill(['name' => 'Bwalya Chileshe-Mumba-Tembo Junior the Third of Kitwe', 'checked_in_at' => now()])->save();

        $png = $service->render($long->fresh(), GuestPassCard::for($long->fresh(), $long->event, $long->rsvp));
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(1080, $info[0]);
        // Wrapped and capped, not unbounded: three title lines, two venue lines.
        $this->assertLessThan(2200, $info[1]);

        $bare = $this->attendingGuest(
            eventOverrides: ['venue' => null, 'location_name' => null, 'event_time' => ''],
            rsvpOverrides: ['attendee_count' => 1],
            token: 'bare-img-token',
        );
        $this->assertNotFalse(getimagesizefromstring(
            $service->render($bare, GuestPassCard::for($bare, $bare->event, $bare->rsvp))
        ));

        $cancelled = $this->attendingGuest(eventOverrides: ['cancelled_at' => now()], token: 'cancelled-img-token');
        $this->assertNotFalse(getimagesizefromstring(
            $service->render($cancelled, GuestPassCard::for($cancelled, $cancelled->event, $cancelled->rsvp))
        ));
    }

    public function test_the_card_is_taller_than_a_bare_card_when_it_carries_more(): void
    {
        Storage::fake('local');
        $service = app(GuestPassImageService::class);

        $bare = $this->attendingGuest(eventOverrides: ['venue' => null, 'location_name' => null], rsvpOverrides: ['attendee_count' => 1], token: 'a-token');
        $full = $this->attendingGuest(token: 'b-token');
        $table = EventTable::factory()->for($full->event)->create(['label' => 'Table 7']);
        $full->forceFill(['event_table_id' => $table->id])->save();

        $bareH = getimagesizefromstring($service->render($bare, GuestPassCard::for($bare, $bare->event, $bare->rsvp)))[1];
        $fullH = getimagesizefromstring($service->render($full->fresh(), GuestPassCard::for($full->fresh(), $full->event, $full->rsvp)))[1];

        $this->assertGreaterThan($bareH, $fullH);
    }

    public function test_it_falls_back_to_the_plain_qr_png_when_the_font_is_missing(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $font = resource_path('fonts/DejaVuSans-Bold.ttf');
        $hidden = $font.'.hidden';
        $this->assertTrue(rename($font, $hidden));

        try {
            $response = $this->get(route('rsvp.token.pass-image', $guest->invitation_token));
        } finally {
            rename($hidden, $font);
        }

        $response->assertOk();
        $info = getimagesizefromstring($response->getContent());
        $this->assertNotFalse($info);
        // The bare QR is square — that is how the fallback is told apart from the card.
        $this->assertSame($info[0], $info[1]);
        $this->assertSame([], $this->cachedFiles($guest->invitation_token), 'A fallback must not be cached as if it were the card.');
    }

    public function test_pass_page_and_panel_offer_the_image(): void
    {
        $guest = $this->attendingGuest();
        $url = route('rsvp.token.pass-image', [$guest->invitation_token, 'download' => 1]);

        $this->get(route('rsvp.token.pass', $guest->invitation_token))->assertSee($url, false)->assertSee('Save as image');
        $this->get(route('rsvp.token.show', $guest->invitation_token))->assertSee($url, false);
    }

    public function test_prune_command_sweeps_orphaned_image_folders_too(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $guest = $this->attendingGuest();
        $this->get(route('rsvp.token.pass-image', $guest->invitation_token))->assertOk();

        Storage::disk('local')->put(GuestPassFileCache::IMAGE.'/gone-token/abc.png', 'x');

        $this->artisan('invitation:prune-orphaned-files')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists(GuestPassFileCache::IMAGE.'/'.$guest->invitation_token));
        $this->assertFalse(Storage::disk('local')->exists(GuestPassFileCache::IMAGE.'/gone-token'));
    }
}
