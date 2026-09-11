<?php

namespace Tests\Feature\Api\V1\Staff;

use App\Models\Event;
use App\Models\EventStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice D — /api/v1/host/events/{event}/checkin/links. Mirrors
 * tests/Feature/StaffScannerLinkTest.php / TicketStaffScannerLinkTest.php: staff
 * links apply to both invitation and ticketed events, unlike staff accounts.
 */
class StaffLinksTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_create_list_and_revoke_a_link_on_an_invitation_event(): void
    {
        $owner = User::factory()->pro()->create();
        $event = Event::factory()->for($owner)->create();

        $store = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson(route('api.v1.host.events.checkin.links.store', $event), ['label' => 'Front gate']);

        $store->assertCreated();
        $linkId = $store->json('link.id');
        $this->assertStringContainsString('/checkin/', $store->json('link.scanner_url'));

        // Plain resource collections wrap in "data" by default (unchanged Laravel
        // behavior, same as e.g. GuestGroupController::index) — only single-resource
        // responses built by hand (store()'s ['link' => ...]) skip that wrapper.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson(route('api.v1.host.events.checkin.links.index', $event))
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Front gate');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson(route('api.v1.host.events.checkin.links.destroy', ['event' => $event, 'link' => $linkId]))
            ->assertOk();
    }

    public function test_an_accepted_manager_can_create_a_link_on_a_ticketed_event(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $manager = User::factory()->create();
        EventStaff::factory()->for($event)->manager()->accepted()
            ->create(['user_id' => $manager->id, 'email' => $manager->email]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson(route('api.v1.host.events.checkin.links.store', $event), ['label' => 'Door 2'])
            ->assertCreated();
    }

    public function test_checkin_only_staff_cannot_create_a_link(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->approved()->create();
        $doorStaff = User::factory()->create();
        EventStaff::factory()->for($event)->accepted() // default role is CheckIn
            ->create(['user_id' => $doorStaff->id, 'email' => $doorStaff->email]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($doorStaff))
            ->postJson(route('api.v1.host.events.checkin.links.store', $event), ['label' => 'Door 2'])
            ->assertForbidden();
    }
}
