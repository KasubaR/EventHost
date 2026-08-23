<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class PollPendingPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_poller_verifies_stale_pending_payment(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->withTransactionId('col_poll_1')->create([
            'status' => 'pending',
            'plan_key' => 'base',
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyPayment')->once()->with('col_poll_1')->andReturn([
            'transactionId' => 'col_poll_1',
            'lencoStatus' => 'successful',
            'status' => 'completed',
            'amount' => (float) $payment->amount,
            'currency' => 'ZMW',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        Artisan::call('payments:poll-pending');

        $user->refresh();
        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame(1, $user->event_credits);
        $this->assertSame(SubscriptionTier::Base, $user->subscriptionTier());
    }

    public function test_poller_verifies_by_reference_when_no_transaction_id(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create([
            'status' => 'pending',
            'payment_method' => 'mobile_money',
            'payment_reference' => 'EH-branch-b-ref',
            'lenco_transaction_id' => null,
            'plan_key' => 'base',
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyByReference')->once()->with('EH-branch-b-ref')->andReturn([
            'transactionId' => 'col_branch_b',
            'lencoStatus' => 'successful',
            'status' => 'completed',
            'amount' => (float) $payment->amount,
            'currency' => 'ZMW',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        Artisan::call('payments:poll-pending');

        $payment->refresh();
        $this->assertSame('completed', $payment->status);
        $this->assertSame('col_branch_b', $payment->lenco_transaction_id);
    }

    public function test_poller_force_fails_payments_older_than_max_age(): void
    {
        config(['services.lenco.pending_max_age_hours' => 24]);

        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create([
            'status' => 'pending',
            'payment_reference' => 'EH-old-ref',
        ]);
        $payment->forceFill(['created_at' => now()->subHours(25)])->save();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldNotReceive('verifyPayment');
        $lenco->shouldNotReceive('verifyByReference');
        $this->app->instance(LencoService::class, $lenco);

        Artisan::call('payments:poll-pending');

        $this->assertSame('cancelled', $payment->fresh()->status);
    }

    /**
     * "Payment verification failure" — Lenco being unreachable or erroring
     * mid-poll must not crash the run or corrupt the payment; it's skipped
     * this cycle and picked up again next time. The try/catch around this
     * call already existed; nothing had made the mock actually throw before
     * this test.
     */
    public function test_poller_survives_a_lenco_verification_error_and_leaves_the_payment_pending(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->withTransactionId('col_poll_error')->create([
            'status' => 'pending',
            'plan_key' => 'base',
        ]);
        $payment->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyPayment')->once()->with('col_poll_error')
            ->andThrow(new \RuntimeException('Lenco is unreachable', 503));
        $this->app->instance(LencoService::class, $lenco);

        Artisan::call('payments:poll-pending');

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_poller_respects_max_api_calls_per_run(): void
    {
        config(['services.lenco.poll_max_per_run' => 1, 'services.lenco.poll_throttle_ms' => 0]);

        $user = User::factory()->withoutCredits()->create();

        foreach (['col_cap_1', 'col_cap_2'] as $id) {
            $payment = Payment::factory()->for($user)->withTransactionId($id)->create([
                'status' => 'pending',
            ]);
            $payment->forceFill(['created_at' => now()->subMinutes(5)])->save();
        }

        $lenco = Mockery::mock(LencoService::class);
        $lenco->shouldReceive('verifyPayment')->once()->andReturn([
            'transactionId' => 'col_cap_1',
            'lencoStatus' => 'pending',
            'status' => 'pending',
            'rawResponse' => [],
        ]);
        $this->app->instance(LencoService::class, $lenco);

        Artisan::call('payments:poll-pending');

        $this->assertStringContainsString('1 Lenco call', Artisan::output());
    }
}
