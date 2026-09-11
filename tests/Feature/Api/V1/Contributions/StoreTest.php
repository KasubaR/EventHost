<?php

namespace Tests\Feature\Api\V1\Contributions;

use App\Enums\ContributionStatus;
use App\Jobs\RetryLencoContributionPayment;
use App\Models\Event;
use App\Models\EventContribution;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class StoreTest extends TestCase
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

    public function test_full_amount_single_installment_completes_the_pledge_with_200_not_201(): void
    {
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_api_ctb_1',
            'status' => 'successful',
            'amount' => 100.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Jane Guest',
            'phone' => '0961234567',
            'amount' => '100.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961234567',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['status', 'reference', 'payment_instructions', 'bank_details', 'payment_url']);

        $contribution = EventContribution::query()->where('event_id', $event->id)->firstOrFail();
        $this->assertSame(ContributionStatus::Completed, $contribution->status);
    }

    public function test_split_into_two_installments_first_call_is_partial(): void
    {
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_api_ctb_2',
            'status' => 'successful',
            'amount' => 40.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Partial Payer',
            'phone' => '0966000111',
            'amount' => '40.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966000111',
        ]);

        $response->assertStatus(200);
        $reference = $response->json('reference');

        $contribution = EventContribution::query()->where('reference', $reference)->firstOrFail();
        $this->assertSame(ContributionStatus::Partial, $contribution->status);
        $this->assertSame(60.0, $contribution->remainingAmount());
    }

    public function test_returning_contributor_is_matched_by_phone_not_forked(): void
    {
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->twice()->andReturn([
            'success' => true,
            'transactionId' => 'col_api_ctb_3',
            'status' => 'successful',
            'amount' => 40.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $first = $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Repeat Payer',
            'phone' => '0966222333',
            'amount' => '40.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966222333',
        ])->assertStatus(200)->json();

        $second = $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Repeat Payer',
            'phone' => '+260966222333',
            'amount' => '40.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0966222333',
        ])->assertStatus(200)->json();

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, EventContribution::query()->where('event_id', $event->id)->count());
    }

    public function test_over_remaining_balance_is_rejected(): void
    {
        $event = $this->contributingEvent();

        $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Too Much',
            'phone' => '0961111111',
            'amount' => '500.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0961111111',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_lenco_server_error_queues_a_retry(): void
    {
        Queue::fake();
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Service unavailable', 503));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Queued Payer',
            'phone' => '0967777777',
            'amount' => '50.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0967777777',
        ])->assertStatus(200)->assertJsonPath('success', true);

        Queue::assertPushed(RetryLencoContributionPayment::class);
    }

    public function test_lenco_client_error_returns_422(): void
    {
        $event = $this->contributingEvent();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Invalid phone', 422));
        $this->app->instance(LencoService::class, $lenco);

        $this->postJson(route('api.v1.contribute.store', ['slug' => $event->slug]), [
            'name' => 'Bad Phone',
            'phone' => '0968888888',
            'amount' => '50.00',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'momo_phone' => '0968888888',
        ])->assertStatus(422);
    }
}
