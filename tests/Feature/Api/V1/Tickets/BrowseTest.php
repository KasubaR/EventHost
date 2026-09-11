<?php

namespace Tests\Feature\Api\V1\Tickets;

use App\Enums\CommissionMode;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\EventSlugRedirect;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowseTest extends TestCase
{
    use RefreshDatabase;

    private function approvedTicketedEvent(array $overrides = []): Event
    {
        return Event::factory()->ticketed()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'ticketing_status' => TicketingStatus::Approved,
            'commission_mode' => CommissionMode::Absorb,
        ], $overrides));
    }

    public function test_active_ticket_types_are_listed_inactive_are_excluded(): void
    {
        $event = $this->approvedTicketedEvent();
        TicketType::factory()->for($event)->create(['name' => 'General Admission', 'is_active' => true]);
        TicketType::factory()->for($event)->create(['name' => 'Retired Type', 'is_active' => false]);

        $response = $this->getJson(route('api.v1.tickets.show', ['slug' => $event->slug]));

        $response->assertOk();
        $names = collect($response->json('ticket_types'))->pluck('name');
        $this->assertTrue($names->contains('General Admission'));
        $this->assertFalse($names->contains('Retired Type'));
    }

    public function test_draft_ticketed_event_is_not_found(): void
    {
        $draft = Event::factory()->ticketed()->create([
            'is_published' => true,
            'ticketing_status' => TicketingStatus::Draft,
        ]);

        $this->getJson(route('api.v1.tickets.show', ['slug' => $draft->slug]))
            ->assertNotFound();
    }

    public function test_non_ticketed_event_is_not_found(): void
    {
        $event = Event::factory()->create(['is_published' => true, 'is_public' => true]);

        $this->getJson(route('api.v1.tickets.show', ['slug' => $event->slug]))
            ->assertNotFound();
    }

    public function test_private_ticketed_event_is_forbidden(): void
    {
        $event = $this->approvedTicketedEvent(['is_public' => false]);

        $this->getJson(route('api.v1.tickets.show', ['slug' => $event->slug]))
            ->assertForbidden();
    }

    public function test_renamed_slug_redirects(): void
    {
        $event = $this->approvedTicketedEvent();
        EventSlugRedirect::create(['slug' => 'old-slug', 'event_id' => $event->id, 'created_at' => now()]);

        $this->get(route('api.v1.tickets.show', ['slug' => 'old-slug']))
            ->assertRedirect(route('events.public', ['slug' => $event->slug]));
    }
}
