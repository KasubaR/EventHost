<?php

namespace Tests\Feature\Api\V1;

use App\Models\Event;
use App\Models\EventSlugRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicEventIcsTest extends TestCase
{
    use RefreshDatabase;

    private function publishedPublicEvent(array $overrides = []): Event
    {
        return Event::factory()->published()->create(array_merge([
            'is_public' => true,
        ], $overrides));
    }

    public function test_a_valid_public_event_returns_a_calendar_document(): void
    {
        $event = $this->publishedPublicEvent();

        $response = $this->get("/api/v1/events/{$event->slug}/calendar.ics");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $response->assertSee('BEGIN:VCALENDAR', escape: false);
        $response->assertSee('SUMMARY:'.$event->name, escape: false);
        $response->assertSee('END:VCALENDAR', escape: false);
    }

    public function test_a_soft_status_event_has_no_calendar(): void
    {
        $event = $this->publishedPublicEvent(['cancelled_at' => now()]);

        $this->get("/api/v1/events/{$event->slug}/calendar.ics")->assertNotFound();
    }

    public function test_a_private_invitation_event_still_returns_a_calendar_document(): void
    {
        // Kept in step with the web ics() action: a private event's slug is
        // unlisted, not unreachable.
        $event = $this->publishedPublicEvent(['is_public' => false]);

        $this->get("/api/v1/events/{$event->slug}/calendar.ics")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
    }

    public function test_a_renamed_slug_redirects(): void
    {
        $event = $this->publishedPublicEvent();
        EventSlugRedirect::create(['slug' => 'old-slug', 'event_id' => $event->id, 'created_at' => now()]);

        // PublicInvitationResolver::lookup() always redirects to the web invitation page
        // (events.public), never an ics-specific route — same behavior the existing web
        // ics() action already has, since both share this exact resolver method.
        $this->get('/api/v1/events/old-slug/calendar.ics')->assertRedirect(
            route('events.public', ['slug' => $event->slug])
        );
    }
}
