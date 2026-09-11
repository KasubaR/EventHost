<?php

namespace Tests\Feature\Api\V1\Events;

use App\Enums\EventProductKind;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_preview_an_unpublished_private_event(): void
    {
        $user = User::factory()->create();
        $template = InvitationTemplate::factory()->create();
        $event = Event::factory()->for($user)->create([
            'is_public' => false,
            'invitation_template_id' => $template->id,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/preview")
            ->assertOk()
            ->assertJsonPath('slug', $event->slug)
            ->assertJsonStructure(['invitation']);
    }

    public function test_preview_does_not_increment_the_view_counter(): void
    {
        $user = User::factory()->create();
        $template = InvitationTemplate::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => $template->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/preview")
            ->assertOk();

        $this->assertSame(0, $event->fresh()->invitation_views_count);
    }

    public function test_needs_template_reason_when_no_template_chosen(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(['invitation_template_id' => null]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/preview")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'needs_template');
    }

    public function test_is_ticketed_reason_for_a_ticketed_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/host/events/{$event->id}/preview")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'is_ticketed');
    }

    public function test_accepted_staff_can_preview(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $template = InvitationTemplate::factory()->create();
        $event = Event::factory()->for($owner)->ticketed()->create();
        // Ticketed events always 422 with is_ticketed regardless of viewer, so use
        // an invitation event with an EventStaff row attached directly to prove the
        // policy gate alone (staffRoleFor) admits an accepted staffer.
        $event->update(['product_kind' => EventProductKind::Invitation, 'invitation_template_id' => $template->id]);
        EventStaff::factory()->for($event)->manager()->create([
            'user_id' => $staffer->id,
            'accepted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->getJson("/api/v1/host/events/{$event->id}/preview")
            ->assertOk();
    }

    public function test_non_owner_non_staff_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/host/events/{$event->id}/preview")
            ->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $event = Event::factory()->create();

        $this->getJson("/api/v1/host/events/{$event->id}/preview")->assertUnauthorized();
    }
}
