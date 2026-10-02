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

    private const PATH = '/astragate-sandbox-test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.astragate.client_id' => 'cid',
            'services.astragate.client_secret' => 'csecret',
            'services.astragate.webhook_secret' => 'hush-hush-secret',
        ]);
    }

    private function fakeAstragate(int $statusCode = 4001): void
    {
        Http::fake([
            'auth.dev.astragate.africa/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'api.dev.astragate.africa/v1/payment/collection' => Http::response([
                'success' => true,
                'data' => ['astragateTransactionId' => 'tx-1', 'statusCode' => 4001, 'statusDescription' => 'Transaction received and processing'],
            ]),
            'api.dev.astragate.africa/v1/payment/checkout-sessions' => Http::response([
                'success' => 'true',
                'message' => 'SUCCESS',
                'statusCode' => 185,
                'data' => ['checkoutUrl' => 'https://checkout.dev.astragate.africa/r/checkout?session=agt-cs_1', 'sessionId' => 'agt-cs_1', 'token' => 'jwt'],
            ]),
            'api.dev.astragate.africa/v1/payment/status/*' => Http::response([
                'success' => true,
                'data' => ['statusCode' => $statusCode],
            ]),
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

    public function test_test_page_loads_and_is_not_indexable(): void
    {
        $this->get(self::PATH)->assertOk()->assertSee('noindex', false);
    }

    public function test_test_page_sends_a_collection_and_shows_the_reference(): void
    {
        $this->fakeAstragate();

        $response = $this->post(self::PATH, ['phone' => '0971234567', 'amount' => 5]);

        $response->assertRedirect();
        $this->assertStringContainsString('ref=TEST-', $response->headers->get('Location'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payment/collection')
            && $r->hasHeader('Authorization', 'Bearer tok')
            && str_starts_with($r['correlatorId'], 'TEST-')
            && $r['accountNumber'] === '260971234567'
            && $r['currency'] === 'ZMW'
            && (float) $r['amount'] === 5.0);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_card_checkout_creates_a_session_and_links_to_it(): void
    {
        $this->fakeAstragate();

        $response = $this->post(self::PATH.'/checkout', ['amount' => 5]);

        $response->assertRedirect();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payment/checkout-sessions')
            && $r['paymentMode'] === 'CARD'
            && $r['currency'] === 'ZMW'
            && str_starts_with($r['correlatorId'], 'TEST-')
            && (float) $r['lineItems'][0]['unitPrice'] === 5.0);

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('https://checkout.dev.astragate.africa/r/checkout?session=agt-cs_1', false)
            ->assertSee('Open Astragate checkout');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_card_checkout_caps_the_amount(): void
    {
        $this->fakeAstragate();

        $this->post(self::PATH.'/checkout', ['amount' => 500])->assertSessionHasErrors('amount');
        Http::assertNothingSent();
    }

    public function test_test_page_caps_the_amount(): void
    {
        $this->fakeAstragate();

        $this->post(self::PATH, ['phone' => '0971234567', 'amount' => 500])->assertSessionHasErrors('amount');
        Http::assertNothingSent();
    }

    public function test_callback_updates_the_test_record_and_never_touches_billing(): void
    {
        $this->fakeAstragate();
        $location = $this->post(self::PATH, ['phone' => '0971234567', 'amount' => 5])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $reference = $query['ref'];

        $user = User::factory()->withoutCredits()->create();
        $payment = Payment::factory()->for($user)->create(['status' => 'pending']);

        $this->postJson('/webhooks/astragate/hush-hush-secret', [
            'callbackType' => 'COLLECTION',
            'statusCode' => '4200',
            'correlatorId' => $reference,
            'systemTransactionId' => 'sys-1',
        ])->assertOk();
        // A callback naming a real billing payment is ignored.
        $this->postJson('/webhooks/astragate/hush-hush-secret', [
            'statusCode' => '4200',
            'correlatorId' => $payment->payment_reference,
        ])->assertOk();

        $this->get(self::PATH.'?ref='.$reference)->assertOk()->assertSee('completed')->assertSee('sys-1');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->event_credits);
    }

    public function test_check_asks_astragate_for_the_status(): void
    {
        $this->fakeAstragate(4200);
        $location = $this->post(self::PATH, ['phone' => '0971234567', 'amount' => 5])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->post(self::PATH.'/check/'.$query['ref'])->assertRedirect();

        $this->get(self::PATH.'?ref='.$query['ref'])->assertSee('completed');
    }

    public function test_callback_with_wrong_secret_is_a_404(): void
    {
        $this->postJson('/webhooks/astragate/wrong', ['correlatorId' => 'x', 'statusCode' => '4200'])
            ->assertNotFound();
    }

    public function test_callback_is_a_404_when_no_secret_is_configured(): void
    {
        config(['services.astragate.webhook_secret' => '']);

        $this->postJson('/webhooks/astragate/anything', ['correlatorId' => 'x'])->assertNotFound();
    }
}
