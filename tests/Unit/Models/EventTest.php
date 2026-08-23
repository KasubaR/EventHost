<?php

namespace Tests\Unit\Models;

use App\Enums\SubscriptionTier;
use App\Enums\TicketingStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event::ownerHasPremiumEventTools() has two different rules depending on
 * product kind — this is the single place that decides both, so it's
 * covered directly here rather than only incidentally through the many
 * controllers that call it. Ticketed events unlock on approval regardless
 * of the owner's subscription tier (EventHost earns a commission once sales
 * are live); invitation events still gate on tier, since those are
 * monetized by event credits, not commission.
 */
class EventTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticketed_event_unlocks_premium_tools_on_approval_regardless_of_owner_tier(): void
    {
        $baseOwner = User::factory()->create(); // subscription_tier defaults to 'none'
        $approved = Event::factory()->for($baseOwner)->ticketed()->approved()->create();

        $this->assertTrue($approved->ownerHasPremiumEventTools());
    }

    public function test_ticketed_event_stays_locked_before_approval_even_for_a_pro_owner(): void
    {
        $proOwner = User::factory()->pro()->create();

        foreach ([TicketingStatus::Draft, TicketingStatus::PendingReview, TicketingStatus::Rejected] as $status) {
            $event = Event::factory()->for($proOwner)->ticketed()->create(['ticketing_status' => $status]);

            $this->assertFalse(
                $event->ownerHasPremiumEventTools(),
                "Expected ownerHasPremiumEventTools() to be false for ticketing_status={$status->value}",
            );
        }
    }

    public function test_invitation_event_still_gates_on_owner_subscription_tier(): void
    {
        $baseOwner = User::factory()->create();
        $proOwner = User::factory()->pro()->create();

        $baseEvent = Event::factory()->for($baseOwner)->create();
        $proEvent = Event::factory()->for($proOwner)->create();

        $this->assertFalse($baseEvent->ownerHasPremiumEventTools());
        $this->assertTrue($proEvent->ownerHasPremiumEventTools());
    }

    public function test_invitation_event_owner_downgrading_below_pro_relocks_it(): void
    {
        $owner = User::factory()->create(['subscription_tier' => SubscriptionTier::Pro]);
        $event = Event::factory()->for($owner)->create();

        $this->assertTrue($event->fresh()->ownerHasPremiumEventTools());

        $owner->forceFill(['subscription_tier' => SubscriptionTier::Base])->save();

        $this->assertFalse($event->fresh()->ownerHasPremiumEventTools());
    }
}
