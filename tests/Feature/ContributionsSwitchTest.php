<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contributions are switched off platform-wide (events.contributions.enabled).
 * phpunit.xml turns the switch on so the rest of the contribution suite still
 * exercises the mechanism; these tests turn it back off.
 */
class ContributionsSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function contributingEvent(): Event
    {
        return Event::factory()->published()->create([
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => 100,
            'event_date' => now()->addWeek()->toDateString(),
        ]);
    }

    public function test_event_accepts_contributions_only_when_the_switch_is_on(): void
    {
        $event = $this->contributingEvent();

        config(['events.contributions.enabled' => true]);
        $this->assertTrue($event->acceptsContributions());

        config(['events.contributions.enabled' => false]);
        $this->assertFalse($event->acceptsContributions());
    }

    public function test_contribute_page_is_a_404_while_switched_off(): void
    {
        $event = $this->contributingEvent();

        config(['events.contributions.enabled' => true]);
        $this->get(route('events.public.contribute', $event->slug))->assertOk();

        config(['events.contributions.enabled' => false]);
        $this->get(route('events.public.contribute', $event->slug))->assertNotFound();
    }

    public function test_pledge_cannot_be_started_while_switched_off(): void
    {
        $event = $this->contributingEvent();
        config(['events.contributions.enabled' => false]);

        $this->postJson(route('events.public.contribute.store', $event->slug), [
            'name' => 'Jane Guest',
            'phone' => '0961234567',
            'amount' => '100.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertNotFound();

        $this->assertDatabaseCount('event_contributions', 0);
    }

    public function test_stored_settings_survive_being_switched_off(): void
    {
        $event = $this->contributingEvent();
        config(['events.contributions.enabled' => false]);

        $fresh = $event->fresh();
        $this->assertTrue($fresh->contribution_enabled);
        $this->assertSame('100.00', (string) $fresh->contribution_amount);
    }
}
