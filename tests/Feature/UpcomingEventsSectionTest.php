<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage strip and the /discover listing both surface events that hosts
 * made public. Only published + public + not-yet-happened events may appear:
 * anything else would leak a private or draft invitation onto a public page.
 */
class UpcomingEventsSectionTest extends TestCase
{
    use RefreshDatabase;

    private function publicEvent(array $attributes = []): Event
    {
        return Event::factory()->published()->create($attributes + [
            'is_public' => true,
            'event_date' => now()->addWeek()->format('Y-m-d'),
        ]);
    }

    public function test_an_upcoming_public_event_is_listed_on_both_pages(): void
    {
        $event = $this->publicEvent(['name' => 'Chanda and Mulenga Wedding']);

        $this->get('/')->assertOk()->assertSee($event->name);
        $this->get(route('events.discover'))->assertOk()->assertSee($event->name);
    }

    public function test_a_private_event_is_never_listed(): void
    {
        $event = $this->publicEvent(['name' => 'Private Family Dinner', 'is_public' => false]);

        $this->get('/')->assertDontSee($event->name);
        $this->get(route('events.discover'))->assertDontSee($event->name);
    }

    public function test_an_unpublished_event_is_never_listed(): void
    {
        $event = Event::factory()->create([
            'name' => 'Unpublished Draft Party',
            'is_public' => true,
            'is_published' => false,
            'event_date' => now()->addWeek()->format('Y-m-d'),
        ]);

        $this->get('/')->assertDontSee($event->name);
        $this->get(route('events.discover'))->assertDontSee($event->name);
    }

    public function test_a_past_event_is_never_listed(): void
    {
        $event = $this->publicEvent([
            'name' => 'Last Year Gala',
            'event_date' => now()->subDay()->format('Y-m-d'),
        ]);

        $this->get('/')->assertDontSee($event->name);
        $this->get(route('events.discover'))->assertDontSee($event->name);
    }

    public function test_an_event_happening_today_still_counts_as_upcoming(): void
    {
        $event = $this->publicEvent([
            'name' => 'Tonight Launch Party',
            'event_date' => now()->format('Y-m-d'),
        ]);

        $this->get('/')->assertOk()->assertSee($event->name);
    }

    public function test_the_homepage_section_is_hidden_when_nothing_qualifies(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Upcoming events');
        $response->assertDontSee('id="upcoming-events"', false);
    }

    public function test_the_homepage_shows_the_soonest_six_events(): void
    {
        // Created back to front so insertion order cannot be what sorts them.
        foreach (range(8, 1) as $daysOut) {
            $this->publicEvent([
                'name' => "Event In {$daysOut} Days",
                'event_date' => now()->addDays($daysOut)->format('Y-m-d'),
            ]);
        }

        $response = $this->get('/');

        $response->assertSee('Event In 1 Days');
        $response->assertSee('Event In 6 Days');
        // Seventh and eighth soonest fall outside the strip's limit of 6.
        $response->assertDontSee('Event In 7 Days');
        $response->assertDontSee('Event In 8 Days');

        // The full set is still reachable from the browse page.
        $this->get(route('events.discover'))->assertSee('Event In 8 Days');
    }

    public function test_the_old_features_block_is_gone(): void
    {
        $this->get('/')->assertDontSee('Everything you need to host with confidence');
    }

    public function test_a_listed_card_links_to_the_public_invitation(): void
    {
        $event = $this->publicEvent();

        $this->get('/')->assertSee(route('events.public', $event->slug), false);
    }

    public function test_the_discover_page_shows_an_empty_state_when_there_is_nothing(): void
    {
        $this->get(route('events.discover'))
            ->assertOk()
            ->assertSee('No upcoming public events yet');
    }
}
