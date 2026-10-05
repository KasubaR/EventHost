<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\InvitationTemplate;
use App\Services\InvitationCustomizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicInvitationPageTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPublicEvent(array $overrides = []): Event
    {
        return Event::factory()->published()->create(array_merge([
            'is_public' => true,
        ], $overrides));
    }

    public function test_map_links_render_without_a_google_maps_key(): void
    {
        config(['services.google_maps.key' => null]);

        $event = $this->publishedPublicEvent([
            'latitude' => -15.4067,
            'longitude' => 28.2871,
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('Open in Google Maps', escape: false);
        $response->assertSee('Get Directions', escape: false);
        $response->assertDontSee('evt-map-embed', escape: false);
    }

    public function test_map_embed_and_directions_use_place_id_when_a_google_maps_key_is_set(): void
    {
        config(['services.google_maps.key' => 'test-maps-key']);

        $event = $this->publishedPublicEvent([
            'latitude' => -15.4067,
            'longitude' => 28.2871,
            'google_place_id' => 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
            'formatted_address' => '123 Garden Terrace, Lusaka, Zambia',
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('evt-map-embed', escape: false);
        $response->assertSee('q=place_id%3AChIJd8BlQ2BZwokRAFUEcm_qrcA', escape: false);
        $response->assertSee('destination_place_id=ChIJd8BlQ2BZwokRAFUEcm_qrcA', escape: false);
    }

    public function test_public_page_includes_open_graph_and_canonical_meta_tags(): void
    {
        $event = $this->publishedPublicEvent([
            'description' => '<p>Join us for cake.</p>',
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('<meta property="og:title"', escape: false);
        $response->assertSee($event->name, escape: false);
        $response->assertSee('<meta property="og:image"', escape: false);
        $response->assertSee('<meta name="twitter:card"', escape: false);
        $response->assertSee('<link rel="canonical"', escape: false);
        $response->assertSee(route('events.public', ['slug' => $event->slug]), escape: false);
        $response->assertSee('Join us for cake.', escape: false);
        $response->assertSee(route('events.public.ics', ['slug' => $event->slug]), escape: false);
        $response->assertSee('evt-calendar-actions', escape: false);
    }

    public function test_og_image_uses_first_hero_portrait_when_botanical_has_no_cover(): void
    {
        // The photo must exist: a file that is gone is skipped for guests (InvitationMediaHealth).
        Storage::fake('public');
        Storage::disk('public')->put('invitation-couple/1/portrait.webp', 'x');

        $tpl = InvitationTemplate::query()
            ->where('slug', 'graduation-template-2-botanical-blush')
            ->firstOrFail();

        $event = $this->publishedPublicEvent([
            'invitation_template_id' => $tpl->id,
            'cover_image' => null,
            'invitation_customization' => [
                'schema_version' => InvitationCustomizationService::CURRENT_SCHEMA_VERSION,
                'media' => [
                    'gallery' => [],
                    'hero_portrait' => null,
                    'couple_photos' => ['invitation-couple/1/portrait.webp'],
                ],
            ],
        ]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk()
            ->assertSee('og:image" content="'.asset('storage/invitation-couple/1/portrait.webp').'"', false);
    }

    public function test_private_published_invitation_event_renders_its_page_and_ics(): void
    {
        // Private events are unlisted, not unreachable — see
        // PublicInvitationLifecycleTest for the "never on Discover" half of this.
        $event = $this->publishedPublicEvent(['is_public' => false]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk();

        $this->get(route('events.public.ics', ['slug' => $event->slug]))
            ->assertOk();
    }

    public function test_ics_download_returns_valid_calendar_document(): void
    {
        $event = $this->publishedPublicEvent(['slug' => 'summer-party']);

        $response = $this->get(route('events.public.ics', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $response->assertSee('BEGIN:VCALENDAR', escape: false);
        $response->assertSee('BEGIN:VEVENT', escape: false);
        $response->assertSee('SUMMARY:'.$event->name, escape: false);
        $response->assertSee('END:VCALENDAR', escape: false);
    }

    public function test_public_invitation_shows_inline_rsvp_form_when_open_and_public(): void
    {
        $event = $this->publishedPublicEvent([
            'rsvp_deadline' => null,
        ]);

        $response = $this->get(route('events.public', ['slug' => $event->slug]));

        $response->assertOk();
        $response->assertSee('evt-inline-rsvp', escape: false);
        $response->assertSee(route('rsvp.open.store', ['slug' => $event->slug]), escape: false);
    }

    public function test_unpublished_event_calendar_ics_returns_404(): void
    {
        $event = Event::factory()->create([
            'is_published' => false,
            'is_public' => true,
        ]);

        $this->get(route('events.public.ics', ['slug' => $event->slug]))
            ->assertNotFound();
    }
}
