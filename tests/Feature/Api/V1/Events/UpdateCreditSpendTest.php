<?php

namespace Tests\Feature\Api\V1\Events;

use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The explicit "Done when" proof for Slice C1: creating and publishing an event via
 * the API spends exactly one credit, republishing spends none more, and redefining
 * a past+published event's identity spends a further, separately-tagged credit.
 * Mirrors EventCreditTest.php's existing web-side proof structure against the new
 * api.v1.host.events.* routes.
 */
class UpdateCreditSpendTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_publishing_a_draft_spends_exactly_one_credit(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->create(); // draft, future date

        $this->assertSame(0, CreditTransaction::where('event_id', $event->id)->count());

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['publish' => true])
            ->assertOk()
            ->assertJsonPath('event.is_published', true);

        $this->assertSame(1, CreditTransaction::where('event_id', $event->id)->count());
        $this->assertSame(
            CreditTransaction::REASON_EVENT_PUBLISHED,
            CreditTransaction::where('event_id', $event->id)->value('reason')
        );
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_republishing_does_not_spend_a_second_credit(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->published()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['publish' => true])
            ->assertOk();

        $this->assertSame(0, CreditTransaction::where('event_id', $event->id)->count());
        $this->assertSame(2, $user->fresh()->event_credits);
    }

    public function test_redefining_a_past_published_events_identity_spends_one_credit(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->published()->create([
            'event_date' => now()->subMonth()->format('Y-m-d'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['name' => 'A whole new name'])
            ->assertOk();

        $this->assertSame(1, CreditTransaction::where('event_id', $event->id)->count());
        $this->assertSame(
            CreditTransaction::REASON_EVENT_REDEFINED,
            CreditTransaction::where('event_id', $event->id)->value('reason')
        );
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_non_identity_edits_to_a_past_event_stay_free(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->published()->create([
            'event_date' => now()->subMonth()->format('Y-m-d'),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['description' => 'A retroactive note'])
            ->assertOk();

        $this->assertSame(0, CreditTransaction::where('event_id', $event->id)->count());
        $this->assertSame(2, $user->fresh()->event_credits);
    }

    public function test_publishing_a_past_draft_with_an_identity_change_spends_only_one_credit(): void
    {
        $user = User::factory()->withCredits(2)->create();
        $event = Event::factory()->for($user)->create([
            'event_date' => now()->subMonth()->format('Y-m-d'),
        ]); // still a draft — not yet published

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['publish' => true, 'name' => 'Renamed & Published'])
            ->assertOk();

        $this->assertSame(1, CreditTransaction::where('event_id', $event->id)->count());
        $this->assertSame(
            CreditTransaction::REASON_EVENT_PUBLISHED,
            CreditTransaction::where('event_id', $event->id)->value('reason')
        );
        $this->assertSame(1, $user->fresh()->event_credits);
    }

    public function test_publishing_without_credits_returns_422_and_spends_nothing(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $event = Event::factory()->for($user)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/host/events/{$event->id}", ['publish' => true]);

        $response->assertStatus(422)->assertJsonPath('error', 'no_event_credits');

        $this->assertFalse($event->fresh()->is_published);
        $this->assertSame(0, CreditTransaction::where('event_id', $event->id)->count());
    }
}
