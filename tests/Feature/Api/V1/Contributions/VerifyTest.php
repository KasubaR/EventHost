<?php

namespace Tests\Feature\Api\V1\Contributions;

use App\Enums\ContributionStatus;
use App\Models\ContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VerifyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function contributingEvent(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'is_published' => true,
            'is_public' => true,
            'contribution_enabled' => true,
            'contribution_amount' => '100.00',
        ], $overrides));
    }

    public function test_success_returns_200_with_no_redirect_url_key(): void
    {
        $event = $this->contributingEvent();
        $contribution = EventContribution::factory()->for($event)->partial()->create(['target_amount' => '100.00']);
        $payment = ContributionPayment::factory()->for($contribution, 'contribution')->create([
            'status' => 'pending',
            'amount' => '40.00',
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyByReference')->once()->andReturn([
            'status' => 'successful',
            'amount' => (float) $payment->amount,
            'currency' => 'ZMW',
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->getJson(route('api.v1.contributions.verify', ['reference' => $contribution->reference]));

        $response->assertOk()->assertJsonPath('success', true);
        // Web's twin includes redirect_url (a Blade page URL) — the API drops it, see the
        // Slice B3 plan, design decision #3.
        $response->assertJsonMissingPath('redirect_url');
    }

    public function test_lenco_error_returns_502(): void
    {
        $event = $this->contributingEvent();
        $contribution = EventContribution::factory()->for($event)->partial()->create(['target_amount' => '100.00']);
        ContributionPayment::factory()->for($contribution, 'contribution')->create(['status' => 'pending']);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyByReference')->once()->andThrow(new \RuntimeException('Lenco unreachable', 502));
        $this->app->instance(LencoService::class, $lenco);

        $this->getJson(route('api.v1.contributions.verify', ['reference' => $contribution->reference]))
            ->assertStatus(502)
            ->assertJsonPath('status', ContributionStatus::Partial->value);
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $this->getJson(route('api.v1.contributions.verify', ['reference' => 'CTB-does-not-exist']))
            ->assertNotFound();
    }
}
