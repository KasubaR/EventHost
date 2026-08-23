<?php

namespace Tests\Feature;

use App\Enums\EventStaffRole;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

/**
 * Phase 18 — staff accounts on ticketed events only (see plans/staff-access.md
 * §1). EventStaffController 404s for every action on an invitation/RSVP
 * event, so every event here is ->ticketed().
 */
class EventStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_staff_and_a_pending_invite_is_created(): void
    {
        NotificationFacade::fake();

        // Base tier deliberately, not ->pro() — ticketed events unlock staff
        // on approval, not subscription tier (EventHost earns a commission
        // once sales are live, see Event::ownerHasPremiumEventTools()).
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();

        $this->actingAs($owner)
            ->post(route('events.staff.store', $event), [
                'name' => 'Door Dan',
                'email' => 'dan@example.com',
                'role' => EventStaffRole::CheckIn->value,
            ])
            ->assertRedirect(route('events.staff.index', $event));

        $staff = EventStaff::query()->where('event_id', $event->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame('dan@example.com', $staff->email);
        $this->assertSame('Door Dan', $staff->name);
        $this->assertTrue($staff->role === EventStaffRole::CheckIn);
        $this->assertTrue($staff->isPending());
        $this->assertNotNull($staff->invite_token);
    }

    public function test_staff_is_not_available_on_invitation_events(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(); // default kind is Invitation

        $this->actingAs($owner)
            ->get(route('events.staff.index', $event))
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('events.staff.store', $event), [
                'name' => 'Door Dan',
                'email' => 'dan@example.com',
                'role' => EventStaffRole::CheckIn->value,
            ])
            ->assertNotFound();

        $this->assertSame(0, EventStaff::query()->count());
    }

    /**
     * The gate is ticketing approval, not subscription tier — a Pro owner is
     * blocked here exactly as a base-tier one would be, proving tier is
     * irrelevant. ->ticketed() defaults to Draft (unapproved).
     */
    public function test_inviting_staff_on_an_unapproved_ticketed_event_is_forbidden(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($owner)
            ->post(route('events.staff.store', $event), [
                'name' => 'Door Dan',
                'email' => 'dan@example.com',
                'role' => EventStaffRole::CheckIn->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, EventStaff::query()->count());
    }

    public function test_non_owner_cannot_view_or_manage_staff(): void
    {
        $owner = User::factory()->pro()->create();
        $stranger = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();

        $this->actingAs($stranger)
            ->get(route('events.staff.index', $event))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('events.staff.store', $event), [
                'name' => 'Door Dan',
                'email' => 'dan@example.com',
                'role' => EventStaffRole::CheckIn->value,
            ])
            ->assertForbidden();
    }

    public function test_a_manager_cannot_invite_or_remove_other_staff(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()->create(['user_id' => $manager->id, 'email' => $manager->email]);
        $other = EventStaff::factory()->for($event)->create();

        $this->actingAs($manager)
            ->get(route('events.staff.index', $event))
            ->assertForbidden();

        $this->actingAs($manager)
            ->delete(route('events.staff.destroy', ['event' => $event, 'eventStaff' => $other]))
            ->assertForbidden();
    }

    /**
     * EventStaffPolicy::update()/delete() are owner-only on every ability,
     * not just the ones exercised above — resend and the role-change PATCH
     * are separate route/controller actions with their own authorize()
     * calls, so each needs its own coverage rather than trusting the
     * destroy() case to stand in for them.
     */
    public function test_a_manager_cannot_resend_an_invite_or_change_a_role(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()->create(['user_id' => $manager->id, 'email' => $manager->email]);
        $other = EventStaff::factory()->for($event)->create();

        $this->actingAs($manager)
            ->post(route('events.staff.resend', ['event' => $event, 'eventStaff' => $other]))
            ->assertForbidden();

        $this->actingAs($manager)
            ->patch(route('events.staff.update', ['event' => $event, 'eventStaff' => $other]), [
                'role' => EventStaffRole::Manager->value,
            ])
            ->assertForbidden();

        $this->assertTrue($other->fresh()->role === EventStaffRole::CheckIn);
    }

    /**
     * EventStaff rows can only ever be created on a ticketed event (the
     * isTicketed() guard in EventStaffController::store()/index()), so this
     * can't happen through the app — but update()/resend()/destroy() each
     * carry their own isTicketed() guard too, and that's only provable by
     * constructing the otherwise-impossible row directly.
     */
    public function test_staff_row_actions_404_if_the_event_is_not_ticketed(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create(); // default kind is Invitation
        $staff = EventStaff::factory()->for($event)->accepted()->create();

        $this->actingAs($owner)
            ->patch(route('events.staff.update', ['event' => $event, 'eventStaff' => $staff]), [
                'role' => EventStaffRole::Manager->value,
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->post(route('events.staff.resend', ['event' => $event, 'eventStaff' => $staff]))
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('events.staff.destroy', ['event' => $event, 'eventStaff' => $staff]))
            ->assertNotFound();

        $this->assertNotNull(EventStaff::find($staff->id));
    }

    public function test_manager_cannot_publish_or_delete_the_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()->create(['user_id' => $manager->id, 'email' => $manager->email]);

        $this->actingAs($manager)
            ->patch(route('events.publish', $event))
            ->assertForbidden();

        $this->actingAs($manager)
            ->delete(route('events.destroy', $event))
            ->assertForbidden();

        $this->assertNotNull($event->fresh());
    }

    public function test_dashboard_and_events_index_list_events_the_user_staffs(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create(['name' => 'Staffed Concert']);
        $staffer = User::factory()->create();
        EventStaff::factory()->for($event)->accepted()->create(['user_id' => $staffer->id, 'email' => $staffer->email]);

        $this->actingAs($staffer)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Staffed Concert');

        $this->actingAs($staffer)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Staffed Concert');
    }

    public function test_owner_can_change_a_staff_members_role(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->accepted()->create();

        $this->actingAs($owner)
            ->patch(route('events.staff.update', ['event' => $event, 'eventStaff' => $staff]), [
                'role' => EventStaffRole::Manager->value,
            ])
            ->assertRedirect(route('events.staff.index', $event));

        $this->assertTrue($staff->fresh()->role === EventStaffRole::Manager);
    }

    public function test_owner_can_remove_staff(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staff = EventStaff::factory()->for($event)->accepted()->create();

        $this->actingAs($owner)
            ->delete(route('events.staff.destroy', ['event' => $event, 'eventStaff' => $staff]))
            ->assertRedirect(route('events.staff.index', $event));

        $this->assertNull(EventStaff::find($staff->id));
    }

    public function test_accepted_manager_gains_ticket_management_access(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()->create(['user_id' => $manager->id, 'email' => $manager->email]);

        $this->actingAs($manager)
            ->get(route('events.ticket-types.index', $event))
            ->assertOk();
    }

    /**
     * A Manager can otherwise do anything ticketing-related, but submitting
     * for review starts the pipeline that ends in EventHost activating
     * ticket sales — a billing-adjacent action, same as events.publish for
     * invitation events. EventStaffRole::Manager's own copy promises "Cannot
     * activate sales"; EventPolicy::publish() is where that's enforced.
     */
    public function test_manager_cannot_submit_ticketing_for_review(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        TicketType::factory()->for($event)->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()->create(['user_id' => $manager->id, 'email' => $manager->email]);

        $this->actingAs($manager)
            ->post(route('events.ticketing.submit', $event))
            ->assertForbidden();

        $this->assertSame(TicketingStatus::Draft, $event->fresh()->ticketing_status);
    }

    public function test_checkin_staff_cannot_manage_tickets(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $staffer = User::factory()->create();
        EventStaff::factory()->for($event)->accepted()->create(['user_id' => $staffer->id, 'email' => $staffer->email]);

        $this->actingAs($staffer)
            ->get(route('events.ticket-types.index', $event))
            ->assertForbidden();

        $ticket = Ticket::factory()->for($event)->create();
        $this->actingAs($staffer)
            ->post(route('events.tickets.cancel', ['event' => $event, 'ticket' => $ticket]))
            ->assertForbidden();
    }

    public function test_checkin_staff_can_reach_the_checkin_scanner(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $staffer = User::factory()->create();
        EventStaff::factory()->for($event)->accepted()->create(['user_id' => $staffer->id, 'email' => $staffer->email]);

        $this->actingAs($staffer)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertOk();
    }

    public function test_checkin_staff_can_confirm_a_ticket_but_cannot_cancel_it(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create(['event_date' => now()->toDateString()]);
        $ticket = Ticket::factory()->for($event)->create();
        $staffer = User::factory()->create();
        EventStaff::factory()->for($event)->accepted()->create(['user_id' => $staffer->id, 'email' => $staffer->email]);

        $this->actingAs($staffer)
            ->postJson(route('events.tickets.checkin.confirm-ticket', ['event' => $event, 'ticket' => $ticket]))
            ->assertOk();

        $this->actingAs($staffer)
            ->post(route('events.tickets.cancel', ['event' => $event, 'ticket' => $ticket]))
            ->assertForbidden();
    }

    public function test_a_stranger_has_no_access_at_all(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('events.tickets.checkin.scan', $event))
            ->assertForbidden();
    }
}
