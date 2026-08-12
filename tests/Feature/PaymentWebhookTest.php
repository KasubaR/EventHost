<?php

namespace Tests\Feature;

use App\Enums\SubscriptionTier;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $apiSecret = 'test-secret-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.lenco.api_secret_key' => $this->apiSecret]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = json_encode(['data' => ['id' => 'col_x', 'status' => 'successful']]);

        $this->call(
            'POST',
            route('lenco.webhook'),
            [],
            [],
            [],
            ['HTTP_X-Lenco-Signature' => 'invalid'],
            $payload,
        )->assertStatus(401);
    }

    public function test_webhook_completes_payment_with_valid_signature(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->withTransactionId('col_webhook_1')->create([
            'amount' => 450.00,
            'currency' => 'ZMW',
            'plan_key' => 'base',
            'status' => 'pending',
        ]);

        $payload = json_encode([
            'status' => 'successful',
            'data' => [
                'id' => 'col_webhook_1',
                'reference' => $payment->payment_reference,
                'status' => 'successful',
                'amount' => 450.00,
                'currency' => 'ZMW',
            ],
        ]);

        $signature = $this->signPayload($payload);

        $this->call(
            'POST',
            route('lenco.webhook'),
            [],
            [],
            [],
            [
                'HTTP_X-Lenco-Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $payload,
        )->assertOk();

        $user->refresh();
        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame(1, $user->event_credits);
        $this->assertSame(SubscriptionTier::Base, $user->subscriptionTier());
    }

    public function test_webhook_fails_payment_on_amount_mismatch(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->withTransactionId('col_mismatch')->create([
            'amount' => 450.00,
            'currency' => 'ZMW',
            'status' => 'pending',
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'col_mismatch',
                'reference' => $payment->payment_reference,
                'status' => 'successful',
                'amount' => 999.00,
                'currency' => 'ZMW',
            ],
        ]);

        $this->call(
            'POST',
            route('lenco.webhook'),
            [],
            [],
            [],
            ['HTTP_X-Lenco-Signature' => $this->signPayload($payload)],
            $payload,
        )->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_webhook_finds_payment_by_reference_first(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create([
            'amount' => 450.00,
            'currency' => 'ZMW',
            'plan_key' => 'base',
            'status' => 'pending',
            'payment_reference' => 'EH-ref-first',
            'lenco_transaction_id' => null,
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'col_webhook_new',
                'reference' => 'EH-ref-first',
                'status' => 'successful',
                'amount' => 450.00,
                'currency' => 'ZMW',
            ],
        ]);

        $this->call(
            'POST',
            route('lenco.webhook'),
            [],
            [],
            [],
            ['HTTP_X-Lenco-Signature' => $this->signPayload($payload)],
            $payload,
        )->assertOk();

        $user->refresh();
        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame(1, $user->event_credits);
    }

    private function signPayload(string $payload): string
    {
        $hashKey = hash('sha256', $this->apiSecret);

        return hash_hmac('sha512', $payload, $hashKey);
    }
}
