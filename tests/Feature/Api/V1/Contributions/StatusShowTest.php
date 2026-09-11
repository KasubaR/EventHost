<?php

namespace Tests\Feature\Api\V1\Contributions;

use App\Enums\ContributionStatus;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusShowTest extends TestCase
{
    use RefreshDatabase;

    private function contributingEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ], $overrides));
    }

    public function test_returns_the_full_contribution_shape(): void
    {
        $event = $this->contributingEvent();
        $contribution = EventContribution::factory()->for($event)->create([
            'target_amount' => '100.00',
            'amount_paid' => '40.00',
            'status' => ContributionStatus::Partial,
        ]);
        ContributionPayment::factory()->for($contribution, 'contribution')->create([
            'status' => 'completed',
        ]);

        $response = $this->getJson(route('api.v1.contributions.show', ['reference' => $contribution->reference]));

        $response->assertOk()
            ->assertJsonPath('reference', $contribution->reference)
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('is_completed', false)
            ->assertJsonPath('remaining_amount', '60.00')
            ->assertJsonStructure(['event', 'contributor' => ['name', 'phone', 'email'], 'latest_payment' => ['status']]);
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $this->getJson(route('api.v1.contributions.show', ['reference' => 'CTB-does-not-exist']))
            ->assertNotFound();
    }
}
