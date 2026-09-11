<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * pause/resume/cancel/uncancel have no existing test coverage anywhere (web or
 * API) — written from scratch off the exact guard conditions in EventController.
 */
class LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_pause_hides_a_live_invitation(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/pause")
            ->assertOk()
            ->assertJsonPath('is_invitation_paused', true);

        $this->assertNotNull($event->fresh()->invitation_paused_at);
    }

    public function test_pause_rejects_an_unpublished_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create(); // draft

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/pause")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_lifecycle_transition');
    }

    public function test_pause_rejects_an_already_cancelled_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['cancelled_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/pause")
            ->assertStatus(422);
    }

    public function test_resume_clears_the_pause_with_no_guard(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['invitation_paused_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/resume")
            ->assertOk()
            ->assertJsonPath('is_invitation_paused', false);

        $this->assertNull($event->fresh()->invitation_paused_at);
    }

    public function test_resume_is_a_harmless_no_op_when_never_paused(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/resume")
            ->assertOk();
    }

    public function test_cancel_marks_the_event_cancelled_and_clears_any_pause(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['invitation_paused_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/cancel")
            ->assertOk()
            ->assertJsonPath('is_cancelled', true)
            ->assertJsonPath('is_invitation_paused', false);

        $event->refresh();
        $this->assertNotNull($event->cancelled_at);
        $this->assertNull($event->invitation_paused_at);
    }

    public function test_cancel_rejects_an_unpublished_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_lifecycle_transition');
    }

    public function test_uncancel_reopens_the_event_with_no_guard(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->published()->create(['cancelled_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/uncancel")
            ->assertOk()
            ->assertJsonPath('is_cancelled', false);

        $this->assertNull($event->fresh()->cancelled_at);
    }

    public function test_all_four_actions_are_owner_only(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->published()->create();
        $token = 'Bearer '.$this->tokenFor($stranger);

        foreach (['pause', 'resume', 'cancel', 'uncancel'] as $action) {
            $this->withHeader('Authorization', $token)
                ->patchJson("/api/v1/host/events/{$event->id}/{$action}")
                ->assertForbidden();
        }
    }
}
