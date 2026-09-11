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

class PayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lenco.api_secret_key' => 'test-contribution-secret']);
    }

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

    public function test_installment_completes_a_partial_pledge(): void
    {
        $event = $this->contributingEvent();
        $contribution = EventContribution::factory()->for($event)->partial()->create([
            'target_amount' => '100.00',
        ]);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_api_ctb_pay_1',
            'status' => 'successful',
            'amount' => 60.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('api.v1.contributions.pay', ['reference' => $contribution->reference]), [
            'amount' => '60.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('reference', $contribution->reference);

        $this->assertSame(ContributionStatus::Completed, $contribution->fresh()->status);
    }

    public function test_already_completed_pledge_is_rejected(): void
    {
        $event = $this->contributingEvent();
        $contribution = EventContribution::factory()->for($event)->completed()->create([
            'target_amount' => '100.00',
        ]);

        $this->postJson(route('api.v1.contributions.pay', ['reference' => $contribution->reference]), [
            'amount' => '10.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_payment_already_in_progress_is_rejected(): void
    {
        $event = $this->contributingEvent();
        $contribution = EventContribution::factory()->for($event)->partial()->create([
            'target_amount' => '100.00',
        ]);
        ContributionPayment::factory()->for($contribution, 'contribution')->create([
            'status' => 'pending',
        ]);

        $this->postJson(route('api.v1.contributions.pay', ['reference' => $contribution->reference]), [
            'amount' => '60.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $this->postJson(route('api.v1.contributions.pay', ['reference' => 'CTB-does-not-exist']), [
            'amount' => '10.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ])->assertNotFound();
    }
}
