<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\AstragateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AstragatePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.astragate.enabled' => true,
            'services.astragate.client_id' => 'cid',
            'services.astragate.client_secret' => 'csecret',
            'services.astragate.webhook_secret' => 'hush-hush-secret',
        ]);
    }

    public function test_status_codes_map_to_payment_statuses(): void
    {
        $this->assertSame('completed', AstragateService::mapStatusCode(4200));
        $this->assertSame('pending', AstragateService::mapStatusCode('4001'));
        $this->assertSame('failed', AstragateService::mapStatusCode(4011));
        $this->assertSame('cancelled', AstragateService::mapStatusCode(4007));
        $this->assertSame('pending', AstragateService::mapStatusCode(null));
    }

    public function test_collection_authenticates_then_posts_the_correlator(): void
    {
        Http::fake([
            'auth.dev.astragate.africa/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.dev.astragate.africa/v1/payment/collection' => Http::response([
                'success' => true,
                'data' => ['correlatorId' => 'EH-1', 'astragateTransactionId' => 'tx-1', 'statusCode' => 4001, 'statusDescription' => 'Transaction received and processing'],
            ]),
        ]);

        $result = app(AstragateService::class)->initiateCollection(
            ['reference' => 'EH-1', 'amount' => 450, 'description' => 'Test'],
            '0971234567',
        );

        $this->assertSame('tx-1', $result['transactionId']);
        $this->assertSame('pending', $result['status']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payment/collection')
            && $r->hasHeader('Authorization', 'Bearer tok')
            && $r['correlatorId'] === 'EH-1'
            && $r['accountNumber'] === '260971234567'
            && $r['currency'] === 'ZMW');
    }

    public function test_callback_with_wrong_secret_is_a_404(): void
    {
        $this->postJson('/webhooks/astragate/wrong', ['correlatorId' => 'x', 'statusCode' => '4200'])
            ->assertNotFound();
    }

    public function test_callback_is_a_404_when_no_secret_is_configured(): void
    {
        config(['services.astragate.webhook_secret' => '']);

        $this->postJson('/webhooks/astragate/', ['correlatorId' => 'x'])->assertNotFound();
    }

    public function test_successful_callback_completes_the_payment(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create([
            'amount' => 450.00,
            'currency' => 'ZMW',
            'plan_key' => 'base',
            'status' => 'pending',
            'metadata' => ['gateway' => 'astragate'],
        ]);

        $this->postJson('/webhooks/astragate/hush-hush-secret', [
            'callbackType' => 'COLLECTION',
            'statusCode' => '4200',
            'correlatorId' => $payment->payment_reference,
            'systemTransactionId' => 'a49be5-4a19-459f-a574-00fa82703392',
            'statusDescription' => 'Successful',
        ])->assertOk();

        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_callback_for_a_lenco_payment_is_ignored(): void
    {
        $payment = Payment::factory()->for(User::factory()->create())->create(['status' => 'pending']);

        $this->postJson('/webhooks/astragate/hush-hush-secret', [
            'statusCode' => '4200',
            'correlatorId' => $payment->payment_reference,
        ])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }
}
