<?php

namespace Tests\Feature\Api\V1\Staff;

use App\Enums\EventStaffRole;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Slice D — /api/v1/host/events/{event}/staff. Ticketed events only,
 * owner-only (App\Policies\EventStaffPolicy never grants Manager) — mirrors
 * tests/Feature/EventStaffTest.php's proof shape against the Sanctum route.
 */
class StaffAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_invite_staff(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.staff.store', $event), [
                'name' => 'Door Dan',
                'email' => 'dan@example.com',
                'role' => EventStaffRole::CheckIn->value,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('staff.email', 'dan@example.com');
        $response->assertJsonPath('staff.is_pending', true);

        $this->assertSame(1, EventStaff::query()->where('event_id', $event->id)->count());
    }

    public function test_staff_404s_on_an_invitation_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.staff.index', $event))
            ->assertNotFound();
    }

    /**
     * Owner-only even for an accepted Manager — staff management is never
     * delegated (App\Policies\EventStaffPolicy's own docblock).
     */
    public function test_an_accepted_manager_cannot_manage_staff(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()
            ->create(['user_id' => $manager->id, 'email' => $manager->email]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson(route('api.v1.host.events.staff.index', $event))
            ->assertForbidden();
    }
}
