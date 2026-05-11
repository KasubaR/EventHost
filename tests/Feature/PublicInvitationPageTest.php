<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_private_published_event_returns_403_on_public_page_and_ics(): void
    {
        $event = $this->publishedPublicEvent(['is_public' => false]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertForbidden();

        $this->get(route('events.public.ics', ['slug' => $event->slug]))
            ->assertForbidden();
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
