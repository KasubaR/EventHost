<?php

namespace Tests\Feature\Api\V1\Guests;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\Guest;
use App\Models\GuestGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BulkActionTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_assign_group_updates_the_selected_guests(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $group = GuestGroup::factory()->for($event)->create();
        $g1 = Guest::factory()->for($event)->create();
        $g2 = Guest::factory()->for($event)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'assign_group',
                'guest_ids' => [$g1->id, $g2->id],
                'guest_group_id' => $group->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('action', 'assign_group')
            ->assertJsonPath('affected_count', 2);

        $this->assertSame($group->id, $g1->fresh()->guest_group_id);
        $this->assertSame($group->id, $g2->fresh()->guest_group_id);
    }

    public function test_delete_action_removes_the_selected_guests(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'delete',
                'guest_ids' => [$guest->id],
            ])
            ->assertOk()
            ->assertJsonPath('affected_count', 1);

        $this->assertNull(Guest::find($guest->id));
    }

    public function test_mark_sent_marks_all_selected(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['invitation_sent' => false]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'mark_sent',
                'guest_ids' => [$guest->id],
            ])
            ->assertOk();

        $this->assertTrue($guest->fresh()->invitation_sent);
    }

    public function test_send_reminder_email_is_gated_behind_pro_plus(): void
    {
        $owner = User::factory()->create(); // Base tier
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'send_reminder_email',
                'guest_ids' => [$guest->id],
                'days_until' => 3,
            ]);

        $response->assertStatus(422)->assertJsonPath('error', 'plan_required');
    }

    public function test_send_reminder_email_succeeds_for_pro_plus_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->proPlus()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create(['email' => 'reminder@example.test']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'send_reminder_email',
                'guest_ids' => [$guest->id],
                'days_until' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('affected_count', 1);
    }

    public function test_accepted_manager_staff_can_run_bulk_actions(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();
        EventStaff::factory()->for($event)->manager()->create(['user_id' => $staffer->id, 'accepted_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'mark_sent',
                'guest_ids' => [$guest->id],
            ])
            ->assertOk();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $guest = Guest::factory()->for($event)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/host/events/{$event->id}/guests/bulk", [
                'action' => 'delete',
                'guest_ids' => [$guest->id],
            ])
            ->assertForbidden();

        $this->assertNotNull(Guest::find($guest->id));
    }
}
