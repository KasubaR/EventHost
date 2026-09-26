<?php

namespace Tests\Feature;

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\EventTable;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\GuestPassFileCache;
use App\Support\GuestPassCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 2 of plans/invitation-pass-card.md: the pass as a downloadable, cached PDF.
 */
class GuestPassPdfTest extends TestCase
{
    use RefreshDatabase;

    private function attendingGuest(?User $owner = null, array $rsvpOverrides = [], string $token = 'pass-pdf-token'): Guest
    {
        $event = Event::factory()->for($owner ?? User::factory()->pro()->create())->create([
            'name' => 'Amy and Joe Wedding',
            'event_date' => now()->addDays(20)->startOfDay(),
        ]);

        $guest = Guest::factory()->for($event)->create(['name' => 'Chanda Mwila', 'invitation_token' => $token]);
        Rsvp::factory()->for($guest)->create(array_merge([
            'status' => RsvpStatus::Accepted,
            'attendee_count' => 2,
        ], $rsvpOverrides));

        return $guest;
    }

    private function cachedFiles(string $token): array
    {
        return Storage::disk('local')->files(GuestPassFileCache::PDF.'/'.$token);
    }

    public function test_download_returns_a_real_pdf_named_after_the_event(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $response = $this->get(route('rsvp.token.pass-download', $guest->invitation_token));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('amy-and-joe-wedding-pass.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_pass_is_always_a_single_page_even_with_worst_case_copy(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();
        $guest->event->forceFill([
            'name' => str_repeat('The Extraordinarily Long-Named Annual Charity Gala Dinner and Silent Auction ', 4),
            'venue' => str_repeat('Sunset Gardens Conference and Events Centre, Plot 4471 Great East Road, ', 3),
        ])->save();
        $table = EventTable::factory()->for($guest->event)->create(['label' => str_repeat('VIP Front Left ', 6)]);
        $guest->forceFill([
            'name' => str_repeat('Bwalya Chileshe-Mumba-Tembo ', 5),
            'event_table_id' => $table->id,
            'checked_in_at' => now(),
        ])->save();

        $pdf = $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk()->getContent();

        // A second page would strand the QR — the one thing the guest needs at the door.
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf));
    }

    public function test_second_download_is_served_from_the_cache(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $first = $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->getContent();
        $files = $this->cachedFiles($guest->invitation_token);
        $this->assertCount(1, $files);

        // Poison the cached file: a cache hit must return it verbatim, not re-render.
        Storage::disk('local')->put($files[0], 'CACHED-SENTINEL');

        $second = $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->getContent();

        $this->assertNotSame($first, $second);
        $this->assertSame('CACHED-SENTINEL', $second);
    }

    public function test_changing_anything_printed_on_the_card_renders_a_fresh_file_and_drops_the_stale_one(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk();
        $before = $this->cachedFiles($guest->invitation_token);

        $table = EventTable::factory()->for($guest->event)->create(['label' => 'Table 7']);
        $guest->forceFill(['event_table_id' => $table->id])->save();

        $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk();
        $after = $this->cachedFiles($guest->invitation_token);

        $this->assertCount(1, $after, 'The directory holds one file, not one per edit.');
        $this->assertNotSame($before, $after);
    }

    public function test_ineligible_guests_get_a_404(): void
    {
        Storage::fake('local');

        $declined = $this->attendingGuest(rsvpOverrides: ['status' => RsvpStatus::Declined], token: 'declined-pdf-token');
        $this->get(route('rsvp.token.pass-download', $declined->invitation_token))->assertNotFound();

        $basic = $this->attendingGuest(User::factory()->create(), token: 'basic-pdf-token');
        $this->get(route('rsvp.token.pass-download', $basic->invitation_token))->assertNotFound();

        $this->get(route('rsvp.token.pass-download', 'no-such-token-at-all'))->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles('guest-pass-pdfs'));
    }

    public function test_download_is_throttled_per_ip(): void
    {
        Storage::fake('local');
        $guest = $this->attendingGuest();

        for ($i = 0; $i < 10; $i++) {
            $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk();
        }

        $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertStatus(429);
    }

    public function test_pass_page_and_panel_link_to_the_pdf(): void
    {
        $guest = $this->attendingGuest();
        $url = route('rsvp.token.pass-download', $guest->invitation_token);

        $this->get(route('rsvp.token.pass', $guest->invitation_token))->assertSee($url, false);
        $this->get(route('rsvp.token.show', $guest->invitation_token))->assertSee($url, false);
    }

    public function test_prune_command_removes_pdf_folders_for_tokens_that_no_longer_exist(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $guest = $this->attendingGuest();
        $this->get(route('rsvp.token.pass-download', $guest->invitation_token))->assertOk();

        $root = GuestPassFileCache::PDF;
        Storage::disk('local')->put($root.'/gone-token/abc.pdf', 'x');

        $this->artisan('invitation:prune-orphaned-files')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists($root.'/'.$guest->invitation_token));
        $this->assertFalse(Storage::disk('local')->exists($root.'/gone-token'));
    }

    public function test_pdf_view_prints_the_same_fields_as_the_card(): void
    {
        $guest = $this->attendingGuest();
        $card = GuestPassCard::for($guest, $guest->event, $guest->rsvp);

        $html = view('rsvp.pass-pdf', ['card' => $card, 'qrDataUri' => 'data:,', 'logoDataUri' => null])->render();

        $this->assertStringContainsString('Amy and Joe Wedding', $html);
        $this->assertStringContainsString('Chanda Mwila', $html);
        $this->assertStringContainsString($card->theme['primary'], $html);
    }
}
