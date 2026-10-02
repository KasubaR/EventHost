<?php

namespace Tests\Feature;

use App\Enums\RsvpApprovalStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\PublicDashboardAnalyticsService;
use App\Services\TicketRevenueLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public overview used to show only a count for free-registration events.
 * It now reads their registrations (plans/public-private-portals.md, Phase 5).
 */
class PublicDashboardRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    private function freeEvent(User $user, string $name = 'Open Service'): Event
    {
        return Event::factory()->for($user)->publicAudience()->published()->create(['name' => $name]);
    }

    private function register(Event $event, int $seats = 1, RsvpApprovalStatus $approval = RsvpApprovalStatus::NotRequired, RsvpStatus $status = RsvpStatus::Accepted): Rsvp
    {
        $guest = Guest::factory()->for($event)->create();

        return Rsvp::factory()->for($guest)->create([
            'event_id' => $event->id,
            'status' => $status,
            'attendee_count' => $seats,
            'host_approval_status' => $approval,
        ]);
    }

    public function test_registrations_headcount_and_pending_are_counted_per_rule(): void
    {
        $user = User::factory()->create();
        $event = $this->freeEvent($user);

        $this->register($event, 2);                                              // counts, 2 seats
        $this->register($event, 1, RsvpApprovalStatus::Approved);                // counts
        $this->register($event, 1, RsvpApprovalStatus::Pending);                 // awaiting, not registered
        $this->register($event, 1, RsvpApprovalStatus::Rejected);                // refused, not counted anywhere
        $this->register($event, 0, RsvpApprovalStatus::NotRequired, RsvpStatus::Declined); // declined, ignored

        $reg = app(PublicDashboardAnalyticsService::class)
            ->forUser($user, app(TicketRevenueLedgerService::class))['registrations'];

        $this->assertSame(2, $reg['registered']);
        $this->assertSame(3, $reg['headcount']);
        $this->assertSame(1, $reg['awaiting_approval']);
        $this->assertSame(2, $reg['daily'][13]['count']); // created today
        $this->assertCount(14, $reg['daily']);
    }

    public function test_other_hosts_registrations_are_not_included(): void
    {
        $user = User::factory()->create();
        $this->freeEvent($user);
        $this->register($this->freeEvent(User::factory()->create(), 'Someone Else'), 4);

        $reg = app(PublicDashboardAnalyticsService::class)
            ->forUser($user, app(TicketRevenueLedgerService::class))['registrations'];

        $this->assertSame(0, $reg['registered']);
    }

    public function test_dashboard_shows_the_free_registration_section_with_numbers(): void
    {
        $user = User::factory()->create();
        $event = $this->freeEvent($user, 'Sunday Fellowship');
        $this->register($event, 3);

        $this->actingAs($user)
            ->get(route('public-dashboard'))
            ->assertOk()
            ->assertSee('Free registration')
            ->assertSee('Daily registrations')
            ->assertSee('Sunday Fellowship')
            ->assertSee('1 registered · 3 expected');
    }

    public function test_section_is_hidden_when_the_host_has_only_ticketed_events(): void
    {
        $user = User::factory()->create();
        Event::factory()->for($user)->ticketed()->approved()->published()->create();

        $this->actingAs($user)
            ->get(route('public-dashboard'))
            ->assertOk()
            ->assertDontSee('Daily registrations');
    }
}
