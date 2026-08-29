<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\EventStaffLink;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffScannerLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_staff_scanner_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.checkin.links.store', $event), ['label' => 'Front door'])
            ->assertRedirect(route('events.checkin.scan', $event));

        $link = EventStaffLink::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($link);
        $this->assertSame('Front door', $link->label);
        $this->assertSame(48, strlen($link->token));
    }

    public function test_base_tier_owner_cannot_create_a_staff_scanner_link(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->post(route('events.checkin.links.store', $event), ['label' => 'Front door'])
            ->assertForbidden();

        $this->assertSame(0, EventStaffLink::query()->count());
    }

    public function test_owner_can_revoke_a_staff_scanner_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $this->actingAs($owner)
            ->delete(route('events.checkin.links.destroy', ['event' => $event, 'link' => $link]))
            ->assertRedirect(route('events.checkin.scan', $event));

        $this->assertNull(EventStaffLink::find($link->id));
    }

    public function test_non_owner_cannot_revoke_another_hosts_staff_link(): void
    {
        $owner = User::factory()->pro()->create();
        $stranger = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $this->actingAs($stranger)
            ->delete(route('events.checkin.links.destroy', ['event' => $event, 'link' => $link]))
            ->assertForbidden();

        $this->assertNotNull(EventStaffLink::find($link->id));
    }

    /**
     * Phase 18 — staff accounts only exist on ticketed events (see
     * plans/staff-access.md §1). An accepted Event Manager gets the same
     * reach as the owner over these Phase 17 scanner links (which work on
     * either event kind), via EventAccess::canManage().
     */
    public function test_an_event_manager_can_create_and_revoke_a_staff_scanner_link(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()->create(['user_id' => $manager->id, 'email' => $manager->email]);

        $this->actingAs($manager)
            ->post(route('events.checkin.links.store', $event), ['label' => 'Side door'])
            ->assertRedirect(route('events.tickets.checkin.scan', $event));

        $link = EventStaffLink::query()->where('event_id', $event->id)->firstOrFail();

        $this->actingAs($manager)
            ->delete(route('events.checkin.links.destroy', ['event' => $event, 'link' => $link]))
            ->assertRedirect(route('events.tickets.checkin.scan', $event));

        $this->assertNull(EventStaffLink::find($link->id));
    }

    /**
     * Phase 18 — Check-in Staff can use the scanner but not the ticket
     * management surface behind it — canCheckIn() reaches further than the
     * door, but canManage() does not extend to them.
     */
    public function test_checkin_only_staff_cannot_reach_ticket_management(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $staffer = User::factory()->create();
        EventStaff::factory()->for($event)->accepted()->create(['user_id' => $staffer->id, 'email' => $staffer->email]);

        $this->actingAs($staffer)
            ->get(route('events.ticket-types.index', $event))
            ->assertForbidden();

        $this->actingAs($staffer)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertOk();
    }

    public function test_public_scan_page_is_active_for_a_valid_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();

        $response = $this->get(route('checkin.public.scan', ['staffToken' => $link->token]));

        $response->assertOk();
        $response->assertDontSee("isn't active", false);
    }

    public function test_public_scan_page_is_inactive_for_a_revoked_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->revoked()->create();

        $this->get(route('checkin.public.scan', ['staffToken' => $link->token]))
            ->assertSee("isn't active", false);
    }

    public function test_public_scan_page_is_inactive_for_an_unknown_token(): void
    {
        $this->get(route('checkin.public.scan', ['staffToken' => 'not-a-real-token']))
            ->assertSee("isn't active", false);
    }

    /**
     * Starting now, on the venue's clock, puts the event squarely inside the
     * check-in window whatever wall-clock time the suite runs at. The factory's
     * random event_time made this flaky once the window gained a 12-hour tail.
     */
    private function eventOnToday(User $owner): Event
    {
        $localNow = now()->timezone(config('events.timezone'));

        return Event::factory()->for($owner)->create([
            'event_date' => $localNow->toDateString(),
            'event_time' => $localNow->format('H:i:s'),
        ]);
    }

    public function test_staff_link_confirms_check_in_by_token_without_authentication(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
        $link = EventStaffLink::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create();

        $response = $this->postJson(route('checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $guest->invitation_token,
        ]));

        $response->assertOk();
        $response->assertJson(['already_checked_in' => false]);

        $guest->refresh();
        $this->assertNotNull($guest->checked_in_at);
        $this->assertNull($guest->checked_in_by);
        $this->assertNotNull($link->fresh()->last_used_at);
    }

    public function test_staff_link_confirms_check_in_by_guest_id(): void
    {
        $owner = User::factory()->pro()->create();
        $event = $this->eventOnToday($owner);
        $link = EventStaffLink::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->postJson(route('checkin.public.confirm-guest', [
            'staffToken' => $link->token,
            'guest' => $guest->id,
        ]))->assertOk();

        $this->assertNotNull($guest->fresh()->checked_in_at);
    }

    public function test_revoked_link_cannot_confirm_check_in(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->revoked()->create();
        $guest = Guest::factory()->for($event)->create();

        $this->postJson(route('checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $guest->invitation_token,
        ]))->assertForbidden();

        $this->assertNull($guest->fresh()->checked_in_at);
    }

    public function test_link_for_a_non_premium_event_cannot_confirm_check_in(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->postJson(route('checkin.public.confirm-token', [
            'staffToken' => $link->token,
            'token' => $guest->invitation_token,
        ]))->assertForbidden();
    }

    public function test_lookup_works_via_staff_link(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice Wonder']);

        $response = $this->getJson(route('checkin.public.lookup', ['staffToken' => $link->token]).'?q=Alice');

        $response->assertOk();
        $response->assertJsonCount(1, 'guests');
    }

    public function test_lookup_rejects_an_empty_query(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();
        $link = EventStaffLink::factory()->for($event)->create();
        Guest::factory()->for($event)->create(['name' => 'Alice Wonder']);

        $this->getJson(route('checkin.public.lookup', ['staffToken' => $link->token]).'?q=')
            ->assertOk()
            ->assertJsonCount(0, 'guests');
    }
}
