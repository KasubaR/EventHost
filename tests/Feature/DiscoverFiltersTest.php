<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 item 2 of plans/public-private-portals.md: /discover gets search,
 * event-type, date-range and city/venue filters, public-audience only.
 * UpcomingEventsSectionTest already covers the base publiclyListed()/
 * upcoming() visibility rules this builds on top of.
 */
class DiscoverFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function publicEvent(array $attributes = []): Event
    {
        return Event::factory()->published()->create($attributes + [
            'is_public' => true,
            'event_date' => now()->addWeek()->format('Y-m-d'),
        ]);
    }

    public function test_search_filters_by_event_name(): void
    {
        $this->publicEvent(['name' => 'Chanda and Mulenga Wedding']);
        $this->publicEvent(['name' => 'Downtown Comedy Night']);

        $response = $this->get(route('events.discover', ['q' => 'Comedy']));

        $response->assertOk()
            ->assertSee('Downtown Comedy Night')
            ->assertDontSee('Chanda and Mulenga Wedding');
    }

    public function test_event_type_filter_shows_only_matching_type(): void
    {
        $this->publicEvent(['name' => 'Big Concert', 'event_type' => 'concert']);
        $this->publicEvent(['name' => 'Someones Wedding', 'event_type' => 'wedding']);

        $response = $this->get(route('events.discover', ['type' => 'concert']));

        $response->assertOk()
            ->assertSee('Big Concert')
            ->assertDontSee('Someones Wedding');
    }

    public function test_an_unknown_event_type_value_is_ignored(): void
    {
        $event = $this->publicEvent(['name' => 'Big Concert', 'event_type' => 'concert']);

        $this->get(route('events.discover', ['type' => 'not-a-real-type']))
            ->assertOk()
            ->assertSee($event->name);
    }

    public function test_where_filter_matches_venue_or_location_name(): void
    {
        $this->publicEvent(['name' => 'Lusaka Show', 'venue' => 'Mulungushi Hall', 'location_name' => null]);
        $this->publicEvent(['name' => 'Ndola Show', 'venue' => null, 'location_name' => 'Ndola']);
        $this->publicEvent(['name' => 'Kitwe Show', 'venue' => 'Kitwe Arena', 'location_name' => null]);

        $response = $this->get(route('events.discover', ['where' => 'Ndola']));

        $response->assertOk()
            ->assertSee('Ndola Show')
            ->assertDontSee('Lusaka Show')
            ->assertDontSee('Kitwe Show');
    }

    public function test_when_today_excludes_events_later_than_today(): void
    {
        $todayEvent = $this->publicEvent(['name' => 'Tonight Launch', 'event_date' => now()->format('Y-m-d')]);
        $laterEvent = $this->publicEvent(['name' => 'Next Week Gala', 'event_date' => now()->addWeek()->format('Y-m-d')]);

        $response = $this->get(route('events.discover', ['when' => 'today']));

        $response->assertOk()
            ->assertSee($todayEvent->name)
            ->assertDontSee($laterEvent->name);
    }

    public function test_when_week_excludes_events_further_out(): void
    {
        $soonEvent = $this->publicEvent(['name' => 'This Week Party', 'event_date' => now()->addDays(3)->format('Y-m-d')]);
        $farEvent = $this->publicEvent(['name' => 'Far Future Party', 'event_date' => now()->addDays(30)->format('Y-m-d')]);

        $response = $this->get(route('events.discover', ['when' => 'week']));

        $response->assertOk()
            ->assertSee($soonEvent->name)
            ->assertDontSee($farEvent->name);
    }

    public function test_filters_combine(): void
    {
        $match = $this->publicEvent([
            'name' => 'Lusaka Concert Night',
            'event_type' => 'concert',
            'venue' => 'Lusaka Showgrounds',
        ]);
        $wrongType = $this->publicEvent([
            'name' => 'Lusaka Wedding',
            'event_type' => 'wedding',
            'venue' => 'Lusaka Showgrounds',
        ]);
        $wrongVenue = $this->publicEvent([
            'name' => 'Ndola Concert Night',
            'event_type' => 'concert',
            'venue' => 'Ndola Arena',
        ]);

        $response = $this->get(route('events.discover', ['type' => 'concert', 'where' => 'Lusaka']));

        $response->assertOk()
            ->assertSee($match->name)
            ->assertDontSee($wrongType->name)
            ->assertDontSee($wrongVenue->name);
    }

    public function test_a_private_event_never_appears_through_the_filters(): void
    {
        $event = $this->publicEvent(['name' => 'Secret Family Dinner', 'is_public' => false]);

        $this->get(route('events.discover', ['q' => 'Secret']))
            ->assertOk()
            ->assertDontSee($event->name);
    }

    public function test_no_matches_shows_a_filtered_empty_state_distinct_from_the_no_events_state(): void
    {
        $this->publicEvent(['name' => 'Existing Party']);

        $response = $this->get(route('events.discover', ['q' => 'Nothing Matches This']));

        $response->assertOk()
            ->assertSee('No events match these filters')
            ->assertDontSee('No upcoming public events yet');
    }

    public function test_clear_filters_link_only_shows_when_a_filter_is_active(): void
    {
        $this->publicEvent();

        $this->get(route('events.discover'))
            ->assertOk()
            ->assertDontSee('Clear filters');

        $this->get(route('events.discover', ['q' => 'anything']))
            ->assertOk()
            ->assertSee('Clear filters');
    }

    public function test_filter_values_are_repopulated_into_the_form(): void
    {
        $this->publicEvent(['event_type' => 'concert']);

        $response = $this->get(route('events.discover', [
            'q' => 'Search Term',
            'type' => 'concert',
            'when' => 'week',
            'where' => 'Lusaka',
        ]));

        $response->assertOk()
            ->assertSee('value="Search Term"', false)
            ->assertSee('value="Lusaka"', false)
            ->assertSeeInOrder(['<option value="concert" selected', 'Concert'], false)
            ->assertSeeInOrder(['<option value="week" selected', 'Next 7 days'], false);
    }
}
