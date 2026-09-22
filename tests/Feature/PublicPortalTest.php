<?php

namespace Tests\Feature;

use App\Enums\EventAudience;
use App\Enums\TicketingStatus;
use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3 of plans/public-private-portals.md: the two portal shells — the
 * audience-scoped dashboards and "My Events" indexes, and the sidebar
 * switcher. EventAudienceTest/EventTypeTaxonomyTest already cover the
 * underlying audience mechanics; this covers the portal-facing surface built
 * on top of it.
 */
class PublicPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_dashboard_only_counts_private_audience_events(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->privateAudience()->published()->create();
        Event::factory()->for($user)->publicAudience()->published()->create();
        Event::factory()->for($user)->ticketed()->published()->create();

        $totals = app(DashboardAnalyticsService::class)
            ->forUser($user, EventAudience::Private)['totals'];

        $this->assertSame(1, $totals['events']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_dashboard_analytics_service_without_an_audience_is_unaffected_by_the_split(): void
    {
        // Guards the Android API's own dashboard (Api\V1\DashboardController),
        // which must keep calling forUser($user) with no second argument and
        // keep seeing every owned event regardless of audience.
        $user = User::factory()->create();
        Event::factory()->for($user)->privateAudience()->published()->create();
        Event::factory()->for($user)->publicAudience()->published()->create();
        Event::factory()->for($user)->ticketed()->published()->create();

        $totals = app(DashboardAnalyticsService::class)->forUser($user)['totals'];

        $this->assertSame(3, $totals['events']);
    }

    public function test_public_dashboard_shows_empty_state_with_no_public_events(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->privateAudience()->create();

        $this->actingAs($user)
            ->get(route('public-dashboard'))
            ->assertOk()
            ->assertSee('No public events yet', false);
    }

    public function test_public_dashboard_aggregates_ticket_sales_and_revenue_across_events(): void
    {
        $user = User::factory()->create();
        $eventA = Event::factory()->for($user)->ticketed()->approved()->published()->create();
        $eventB = Event::factory()->for($user)->ticketed()->approved()->published()->create();
        Event::factory()->for($user)->privateAudience()->create(); // must not contribute

        Ticket::factory()->for($eventA)->create(['status' => TicketStatus::Valid]);
        Ticket::factory()->for($eventA)->create(['status' => TicketStatus::Used, 'checked_in_at' => now()]);
        Ticket::factory()->for($eventB)->create(['status' => TicketStatus::Valid]);

        $ledger = app(TicketRevenueLedgerService::class);
        $ledger->recordSale(TicketOrder::factory()->for($eventA)->paid()->create([
            'face_value' => '200.00', 'commission_amount' => '10.00', 'host_amount' => '190.00',
        ]));
        $ledger->recordSale(TicketOrder::factory()->for($eventB)->paid()->create([
            'face_value' => '100.00', 'commission_amount' => '5.00', 'host_amount' => '95.00',
        ]));

        $response = $this->actingAs($user)->get(route('public-dashboard'));

        $response->assertOk()
            ->assertSee('3', false) // tickets sold
            ->assertSee('1', false) // checked in
            ->assertSee('K300.00', false) // gross
            ->assertSee('K285.00', false); // host revenue
    }

    public function test_public_dashboard_lists_events_the_user_staffs_but_not_owns(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create(['name' => 'Staffed Show']);
        $staffer = User::factory()->create();
        EventStaff::factory()->for($event)->accepted()->create([
            'user_id' => $staffer->id,
            'email' => $staffer->email,
        ]);

        $this->actingAs($staffer)
            ->get(route('public-dashboard'))
            ->assertOk()
            ->assertSee('Staffed Show');
    }

    public function test_events_index_and_public_events_index_partition_by_audience(): void
    {
        $user = User::factory()->create();
        $private = Event::factory()->for($user)->privateAudience()->create(['name' => 'Garden Wedding']);
        $ticketed = Event::factory()->for($user)->ticketed()->create(['name' => 'Big Concert']);

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Garden Wedding')
            ->assertDontSee('Big Concert');

        $this->actingAs($user)
            ->get(route('public-events.index'))
            ->assertOk()
            ->assertSee('Big Concert')
            ->assertDontSee('Garden Wedding');

        $this->assertSame(EventAudience::Private, $private->fresh()->audience);
        $this->assertSame(EventAudience::Public, $ticketed->fresh()->audience);
    }

    public function test_deleting_a_private_event_redirects_to_the_private_index(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->privateAudience()->create();

        $this->actingAs($user)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'));
    }

    public function test_deleting_a_public_event_redirects_to_the_public_index(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->actingAs($user)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('public-events.index'));
    }

    public function test_submitting_ticketing_for_review_redirects_to_the_public_index(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        TicketType::factory()->for($event)->create();

        $this->actingAs($user)
            ->post(route('events.ticketing.submit', $event))
            ->assertRedirect(route('public-events.index'))
            ->assertSessionHas('status', 'ticketing-submitted');

        $this->assertSame(TicketingStatus::PendingReview, $event->fresh()->ticketing_status);
    }

    public function test_sidebar_shows_private_portal_active_on_the_private_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSeeInOrder([
            'dash-portal-switch-tab is-active',
            'Private',
        ], false);
    }

    public function test_sidebar_shows_public_portal_active_on_the_public_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('public-dashboard'));

        $response->assertOk();
        $response->assertSeeInOrder([
            'dash-portal-switch-tab is-active',
            'Public',
        ], false);
    }

    /**
     * events.create is shared by both portals (Phase 4's chooser), so its
     * route name alone can't tell the sidebar which one is active — the
     * in-progress ?audience=/?kind= query string has to break the tie. Covers
     * the bug found by manual verification: the sidebar stayed on "Private"
     * all the way through the Public branch of the wizard until this was
     * added to layouts/app.blade.php.
     */
    public function test_sidebar_shows_public_portal_active_while_creating_a_public_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('events.create', ['audience' => 'public']))
            ->assertOk()
            ->assertSeeInOrder(['dash-portal-switch-tab is-active', 'Public'], false);

        $this->actingAs($user)
            ->get(route('events.create', ['audience' => 'public', 'kind' => 'invitation']))
            ->assertOk()
            ->assertSeeInOrder(['dash-portal-switch-tab is-active', 'Public'], false);

        // A bare ?kind=ticketed with no ?audience= is still a valid, working
        // shortcut (unambiguous — ticketed always implies public) even though
        // no view links to it this way any more; still has to resolve to Public.
        $this->actingAs($user)
            ->get(route('events.create', ['kind' => 'ticketed']))
            ->assertOk()
            ->assertSeeInOrder(['dash-portal-switch-tab is-active', 'Public'], false);
    }

    public function test_sidebar_shows_private_portal_active_while_creating_a_private_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('events.create', ['audience' => 'private']))
            ->assertOk()
            ->assertSeeInOrder(['dash-portal-switch-tab is-active', 'Private'], false);
    }

    /**
     * Reported bug: the private dashboard's and private "My Events" page's
     * "New event" buttons linked to the bare chooser (route('events.create')
     * with no params), which offered Public as an option even though the
     * host was already in the private portal. Both now pass ?audience=private,
     * which EventController::resolveCreateProductKind() resolves straight to
     * Invitation — the create page renders the details form directly, with
     * no chooser and no way to reach a Public event from here at all.
     */
    public function test_private_new_event_buttons_skip_the_chooser_and_never_offer_public(): void
    {
        $user = User::factory()->create();

        foreach ([route('dashboard'), route('events.index')] as $page) {
            $html = $this->actingAs($user)->get($page)->getContent();
            $this->assertStringContainsString(
                route('events.create', ['audience' => 'private']),
                $html,
                "New event button on {$page} should link straight to a private event"
            );
        }

        $response = $this->actingAs($user)->get(route('events.create', ['audience' => 'private']));

        $response->assertOk()
            ->assertSee('name="name"', false) // straight to the details form
            ->assertDontSee('Public event', false)
            ->assertDontSee('Private event', false); // no chooser card at all
    }

    /**
     * Same bug, the other side: the public dashboard's and "My Public Events"
     * page's "New event" buttons used to lock straight into the ticketed
     * form (?kind=ticketed), never offering free registration. Both now pass
     * ?audience=public, landing on the Ticketed vs Free registration chooser
     * — and never offering Private.
     */
    public function test_public_new_event_buttons_open_the_public_chooser_and_never_offer_private(): void
    {
        $user = User::factory()->create();

        foreach ([route('public-dashboard'), route('public-events.index')] as $page) {
            $html = $this->actingAs($user)->get($page)->getContent();
            $this->assertStringContainsString(
                route('events.create', ['audience' => 'public']),
                $html,
                "New event button on {$page} should link to the public chooser"
            );
        }

        $response = $this->actingAs($user)->get(route('events.create', ['audience' => 'public']));

        $response->assertOk()
            ->assertSee('Ticketed event', false)
            ->assertSee('Free registration', false)
            ->assertDontSee('Private event', false)
            ->assertDontSee('name="name"', false); // not yet on the details form
    }

    public function test_edit_page_back_link_points_at_the_correct_portal_index(): void
    {
        $user = User::factory()->create();
        $privateEvent = Event::factory()->for($user)->privateAudience()->create();
        $publicEvent = Event::factory()->for($user)->publicAudience()->create();

        $this->actingAs($user)
            ->get(route('events.edit', $privateEvent))
            ->assertSee(route('events.index'), false);

        $this->actingAs($user)
            ->get(route('events.edit', $publicEvent))
            ->assertSee(route('public-events.index'), false);
    }
}
