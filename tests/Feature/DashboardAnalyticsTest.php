<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_invitation_increments_view_counter(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
        ]);

        $this->get(route('events.public', ['slug' => $event->slug]))
            ->assertOk();

        $this->assertSame(1, (int) $event->fresh()->invitation_views_count);
    }

    public function test_dashboard_shows_aggregated_analytics(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
        ]);

        $group = GuestGroup::factory()->for($event)->create(['name' => 'VIP']);

        $gResponded = Guest::factory()->for($event)->create(['email' => 'yes@example.test']);
        $gAwaiting = Guest::factory()->for($event)->create(['email' => 'wait@example.test']);
        Guest::factory()->for($event)->create([
            'email' => 'vip@example.test',
            'guest_group_id' => $group->id,
        ]);

        Rsvp::factory()->forGuest($gResponded)->accepted(2)->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Invitation views')
            ->assertSee('RSVP conversion')
            ->assertSee('33.3%')
            ->assertSee('Daily RSVPs')
            ->assertSee('Guest categories')
            ->assertSee('VIP')
            ->assertSee('Most active guests')
            ->assertSee('2 seats');
    }

    public function test_event_show_includes_analytics_payload_and_totals(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
            'invitation_views_count' => 7,
        ]);

        $group = GuestGroup::factory()->for($event)->create(['name' => 'Family']);

        $guestResponded = Guest::factory()->for($event)->create(['email' => 'ok@example.test']);
        Guest::factory()->for($event)->create([
            'email' => 'noreply@example.test',
            'guest_group_id' => $group->id,
        ]);

        Rsvp::factory()->forGuest($guestResponded)->accepted(3)->create();

        $response = $this->actingAs($user)
            ->get(route('events.show', ['event' => $event]));

        $response->assertOk()
            ->assertSee('id="evt-analytics-json"', false)
            ->assertSee('data-analytics-root', false)
            ->assertSee('Analytics', false)
            ->assertSee('Invitation views', false)
            ->assertSee(number_format(7), false)
            ->assertSee('Family', false)
            ->assertSee('3 seats', false);

        preg_match(
            '#<script[^>]*id="evt-analytics-json"[^>]*>(.*?)</script>#s',
            $response->getContent(),
            $matches,
        );
        $this->assertNotEmpty($matches[1] ?? null);

        $payload = json_decode($matches[1], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('daily_rsvps', $payload);
        $this->assertArrayHasKey('status_chart', $payload);
        $this->assertArrayHasKey('group_breakdown', $payload);
        $this->assertCount(14, $payload['daily_rsvps']);

        $svc = app(DashboardAnalyticsService::class);
        $analytics = $svc->forEvent($event->fresh());
        $this->assertSame(7, $analytics['totals']['invitation_views']);
        $this->assertSame(2, $analytics['totals']['guests']);
        $this->assertSame(1, $analytics['totals']['responded_guests']);
        $this->assertSame(3, $analytics['totals']['accepted_headcount']);
        $this->assertGreaterThan(0, count($analytics['status_chart']));
    }

    public function test_rsvp_breakdown_chart_reflects_status_mix(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create([
            'is_public' => true,
        ]);

        $ga = Guest::factory()->for($event)->create(['email' => 'a@example.test']);
        $gb = Guest::factory()->for($event)->create(['email' => 'b@example.test']);
        $gc = Guest::factory()->for($event)->create(['email' => 'c@example.test']);

        Rsvp::factory()->forGuest($ga)->accepted(1)->create();
        Rsvp::factory()->forGuest($gb)->declined()->create();
        Rsvp::factory()->forGuest($gc)->maybe()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Accepted')
            ->assertSee('Declined')
            ->assertSee('Maybe');
    }
}
