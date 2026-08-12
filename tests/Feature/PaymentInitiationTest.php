<?php

namespace Tests\Feature;

use App\Jobs\RetryLencoPayment;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PaymentInitiationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_guest_cannot_access_billing_page(): void
    {
        $this->get(route('billing.show'))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_billing_page(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)
            ->get(route('billing.show'))
            ->assertOk()
            ->assertSee('Buy event credit', escape: false)
            ->assertSee('Base', escape: false)
            ->assertSee('Pro', escape: false);
    }

    public function test_initiate_rejects_duplicate_in_progress_payment(): void
    {
        $user = User::factory()->withoutCredits()->create();
        Payment::factory()->for($user)->create(['status' => 'pending']);

        $response = $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'base',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ]);

        $response->assertStatus(409);
    }

    public function test_initiate_creates_payment_and_returns_transaction_id(): void
    {
        $user = User::factory()->withoutCredits()->create(['phone' => '0971234567']);

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('generatePaymentReference')->once()->andReturn('EH-1-test-ref');
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andReturn([
            'success' => true,
            'transactionId' => 'col_test_123',
            'reference' => 'EH-1-test-ref',
            'lencoReference' => 'LEN-123',
            'status' => 'pay-offline',
            'amount' => 450.00,
            'currency' => 'ZMW',
            'provider' => 'mtn',
            'paymentInstructions' => 'Approve on your phone.',
            'rawResponse' => ['data' => ['id' => 'col_test_123']],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'base',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0961234567',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('transaction_id', 'col_test_123');

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'plan_key' => 'base',
            'payment_reference' => 'EH-1-test-ref',
            'status' => 'pending',
        ]);
    }

    public function test_initiate_dispatches_retry_job_on_server_error(): void
    {
        Queue::fake();

        $user = User::factory()->withoutCredits()->create();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('generatePaymentReference')->once()->andReturn('EH-1-queued-ref');
        $lenco->shouldReceive('initiateMobileMoneyPayment')->once()->andThrow(new \RuntimeException('Service unavailable', 503));
        $this->app->instance(LencoService::class, $lenco);

        $response = $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'pro',
            'payment_method' => 'mobile_money',
            'provider' => 'airtel',
            'phone' => '0771234567',
        ]);

        $response->assertOk()->assertJsonPath('status', 'queued');

        Queue::assertPushed(RetryLencoPayment::class);
    }

    public function test_initiate_rejects_mismatched_phone_operator(): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'base',
            'payment_method' => 'mobile_money',
            'provider' => 'mtn',
            'phone' => '0771234567',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_initiate_rejects_bank_transfer_when_disabled(): void
    {
        config(['services.lenco.bank_transfer_enabled' => false]);

        $user = User::factory()->withoutCredits()->create();

        $this->actingAs($user)->postJson(route('payment.initiate'), [
            'plan_key' => 'base',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'Zanaco',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }
}
