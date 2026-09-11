<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_publish_spends_a_credit_and_goes_live(): void
    {
        $user = User::factory()->withCredits(1)->create();
        $event = Event::factory()->for($user)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/publish")
            ->assertOk()
            ->assertJsonPath('is_published', true);

        $this->assertSame(0, $user->fresh()->event_credits);
        $this->assertSame(1, CreditTransaction::where('event_id', $event->id)->count());
    }

    public function test_ticketed_events_are_rejected(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->ticketed()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('error', 'ticketed_events_use_admin_approval');

        $this->assertFalse($event->fresh()->is_published);
    }

    public function test_no_credits_returns_402_and_leaves_the_event_a_draft(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($user)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}/publish")
            ->assertStatus(402)
            ->assertJsonPath('error', 'no_event_credits');

        $this->assertFalse($event->fresh()->is_published);
        $this->assertSame(0, CreditTransaction::where('event_id', $event->id)->count());
    }

    public function test_non_owner_is_forbidden(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->patchJson("/api/v1/host/events/{$event->id}/publish")
            ->assertForbidden();
    }
}
