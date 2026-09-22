<?php

namespace Tests\Feature;

use App\Enums\EventAudience;
use App\Models\Admin;
use App\Models\Event;
use App\Services\AdminAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8 of plans/public-private-portals.md: the admin panel's event list,
 * event detail page and platform analytics all surface/filter by audience.
 * Ticketing approval, payouts and contribution admin were confirmed
 * unchanged (they never reference audience), and no admin permission needed
 * to split on it — this only covers the three views that actually changed.
 */
class AdminAudienceFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function superAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_events_index_shows_the_audience_column(): void
    {
        Event::factory()->privateAudience()->create(['name' => 'A Private Wedding']);
        Event::factory()->ticketed()->create(['name' => 'A Public Concert']);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.events.index'))
            ->assertOk()
            ->assertSeeInOrder(['A Private Wedding', 'Private event'])
            ->assertSeeInOrder(['A Public Concert', 'Public event']);
    }

    public function test_events_index_filters_by_audience(): void
    {
        $private = Event::factory()->privateAudience()->create(['name' => 'A Private Wedding']);
        $public = Event::factory()->ticketed()->create(['name' => 'A Public Concert']);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.events.index', ['audience' => 'private']))
            ->assertOk()
            ->assertSee($private->name)
            ->assertDontSee($public->name);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.events.index', ['audience' => 'public']))
            ->assertOk()
            ->assertSee($public->name)
            ->assertDontSee($private->name);
    }

    public function test_an_invalid_audience_value_is_ignored_and_shows_everything(): void
    {
        $private = Event::factory()->privateAudience()->create(['name' => 'A Private Wedding']);
        $public = Event::factory()->ticketed()->create(['name' => 'A Public Concert']);

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.events.index', ['audience' => 'not-a-real-value']))
            ->assertOk()
            ->assertSee($private->name)
            ->assertSee($public->name);
    }

    public function test_event_detail_page_shows_the_audience(): void
    {
        $event = Event::factory()->ticketed()->create();

        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSeeInOrder(['Audience', 'Public event']);
    }

    public function test_analytics_page_shows_audience_filter_chips(): void
    {
        $this->actingAs($this->superAdmin(), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('All audiences')
            ->assertSee('Private event')
            ->assertSee('Public event');
    }

    public function test_chart_payload_scopes_event_derived_charts_by_audience(): void
    {
        Event::factory()->privateAudience()->create(['event_type' => 'wedding']);
        Event::factory()->ticketed()->create(['event_type' => 'concert']);
        Event::factory()->ticketed()->create(['event_type' => 'concert']);

        $service = app(AdminAnalyticsService::class);

        $privateOnly = $service->chartPayload(EventAudience::Private);
        $publicOnly = $service->chartPayload(EventAudience::Public);
        $all = $service->chartPayload();

        $this->assertSame(['wedding' => 1], $this->typeCounts($privateOnly));
        $this->assertSame(['concert' => 2], $this->typeCounts($publicOnly));
        $this->assertSame(['concert' => 2, 'wedding' => 1], $this->typeCounts($all));
    }

    /**
     * @return array<string, int>
     */
    private function typeCounts(array $payload): array
    {
        $counts = [];
        foreach ($payload['event_types'] as $row) {
            $counts[$row['key']] = $row['count'];
        }
        ksort($counts);

        return $counts;
    }
}
