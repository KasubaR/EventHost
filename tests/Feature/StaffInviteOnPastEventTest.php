<?php

namespace Tests\Feature;

use App\Enums\EventStaffRole;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Staff can be invited to an event whose date has passed, on the web and through the mobile API. Nothing in
 * either invite path looks at the event date, and these pin that so a future "locked event" rule has to be
 * a deliberate decision rather than an accident.
 */
class StaffInviteOnPastEventTest extends TestCase
{
    use RefreshDatabase;

    private function pastTicketedEvent(User $owner): Event
    {
        $event = Event::factory()->for($owner)->ticketed()->approved()->create([
            'event_date' => now()->subMonth()->format('Y-m-d'),
        ]);

        $this->assertTrue($event->isLocked(), 'the event must really be in the past');

        return $event;
    }

    public function test_web_invite_works_on_a_past_event(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = $this->pastTicketedEvent($owner);

        $this->actingAs($owner)->get(route('public-events.staff.index', $event))->assertOk();

        $this->post(route('public-events.staff.store', $event), [
            'name' => 'Door Dan',
            'email' => 'dan@example.com',
            'role' => EventStaffRole::CheckIn->value,
        ])->assertRedirect(route('public-events.staff.index', $event));

        $staff = EventStaff::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertTrue($staff->isPending());
    }

    public function test_mobile_api_invite_works_on_a_past_event(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = $this->pastTicketedEvent($owner);
        $token = $owner->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.v1.host.events.staff.store', $event), [
                'name' => 'Door Dan',
                'email' => 'dan@example.com',
                'role' => EventStaffRole::CheckIn->value,
            ])
            ->assertCreated()
            ->assertJsonPath('staff.is_pending', true);
    }

    public function test_the_invitee_can_still_accept_on_a_past_event(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $event = $this->pastTicketedEvent($owner);

        $this->actingAs($owner)->post(route('public-events.staff.store', $event), [
            'name' => 'Door Dan',
            'email' => 'dan@example.com',
            'role' => EventStaffRole::CheckIn->value,
        ]);
        $staff = EventStaff::query()->where('event_id', $event->id)->firstOrFail();
        auth()->logout();

        $this->get(route('staff-invitations.show', $staff->invite_token))->assertOk();
    }
}
