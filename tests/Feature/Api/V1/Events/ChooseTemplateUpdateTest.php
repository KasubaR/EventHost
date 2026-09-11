<?php

namespace Tests\Feature\Api\V1\Events;

use App\Enums\SubscriptionTier;
use App\Models\Event;
use App\Models\EventStaff;
use App\Models\InvitationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChooseTemplateUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_owner_can_choose_an_active_template(): void
    {
        $user = User::factory()->create(); // Base tier
        $event = Event::factory()->for($user)->create();
        $template = InvitationTemplate::factory()->create(['min_subscription_tier' => SubscriptionTier::Base]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/choose-template", [
                'invitation_template_id' => $template->id,
            ])
            ->assertOk()
            ->assertJsonPath('invitation_template_id', $template->id);
    }

    public function test_tier_locked_template_is_rejected_with_the_required_tier_message(): void
    {
        $user = User::factory()->withoutCredits()->create(); // tier none
        $event = Event::factory()->for($user)->create();
        $template = InvitationTemplate::factory()->create(['min_subscription_tier' => SubscriptionTier::Pro]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/choose-template", [
                'invitation_template_id' => $template->id,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('invitation_template_id');
        $this->assertStringContainsString('Pro', $response->json('errors.invitation_template_id.0'));
    }

    public function test_ticketed_event_is_rejected(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();
        $template = InvitationTemplate::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/choose-template", [
                'invitation_template_id' => $template->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'is_ticketed');
    }

    public function test_accepted_manager_staff_can_choose(): void
    {
        $owner = User::factory()->create();
        $staffer = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $template = InvitationTemplate::factory()->create();
        EventStaff::factory()->for($event)->manager()->create([
            'user_id' => $staffer->id,
            'accepted_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($staffer))
            ->patchJson("/api/v1/host/events/{$event->id}/choose-template", [
                'invitation_template_id' => $template->id,
            ])
            ->assertOk();
    }

    public function test_non_owner_non_manager_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $template = InvitationTemplate::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->patchJson("/api/v1/host/events/{$event->id}/choose-template", [
                'invitation_template_id' => $template->id,
            ])
            ->assertForbidden();
    }
}
