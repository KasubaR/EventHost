<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Astragate (https://docs.astragate.africa). Card payments go through its hosted checkout;
 * mobile money and bank transfer are Lenco's. This class shares nothing with
 * {@see LencoService} — its own config (`config/astragate.php`), its own status vocabulary,
 * its own HTTP client. Sandbox-first: base URLs come from config so go-live is an env change.
 *
 * Astragate callbacks carry no signature, so the callback URL itself is the secret and the
 * callback body is never trusted — see `AstragateWebhookController`.
 */
class AstragateService
{
    private const TOKEN_CACHE_KEY = 'astragate.access_token';

    public static function configured(): bool
    {
        return filled(config('astragate.client_id'))
            && filled(config('astragate.client_secret'));
    }

    /** Whether the checkout pages and APIs may offer "Pay by card". */
    public static function cardEnabled(): bool
    {
        return (bool) config('astragate.card_enabled') && self::configured();
    }

    /**
     * Maps an Astragate status code to the app's payment statuses
     * (`pending | processing | completed | failed | cancelled | refunded`).
     */
    public static function mapStatusCode(string|int|null $code): string
    {
        return match ((int) $code) {
            4200 => 'completed',
            4008 => 'processing',
            4011, 4230, 4777 => 'failed',
            4005, 4007 => 'cancelled',
            4220 => 'refunded',
            default => 'pending', // 4000, 4001, 4003, 4999 and anything unknown
        };
    }

    /**
     * Hosted checkout session, card only. The customer pays on Astragate's page.
     *
     * @param  array{reference: string, amount: float, currency?: string, description?: string, name?: string, customer_name?: ?string, customer_email?: ?string}  $context
     *                                                                                                                                                                       `checkoutUrl` already has the session `token` appended — Astragate's checkout page
     *                                                                                                                                                                       does not load without it. `rawResponse` is the response minus that token, safe to store/show.
     * @return array{checkoutUrl: ?string, sessionId: ?string, rawResponse: array<string, mixed>}
     */
    private function createCheckoutSession(array $context): array
    {
        $payload = [
            'lineItems' => [[
                'itemId' => $context['reference'],
                'name' => $context['name'] ?? 'Event Host payment',
                'quantity' => 1,
                'unitPrice' => round((float) $context['amount'], 2),
            ]],
            'currency' => $context['currency'] ?? 'ZMW',
            'correlatorId' => $context['reference'],
            'description' => $context['description'] ?? 'Event Host payment',
            'paymentMode' => 'CARD',
        ];

        $customer = array_filter([
            'customer_name' => $context['customer_name'] ?? null,
            'customer_email' => $context['customer_email'] ?? null,
        ], fn ($value) => is_string($value) && $value !== '');
        if ($customer !== []) {
            $payload['metadata'] = $customer;
        }

        $response = $this->request('POST', '/v1/payment/checkout-sessions', $payload);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $url = $data['checkoutUrl'] ?? null;
        $token = $data['token'] ?? null;
        if (is_string($url) && is_string($token) && $token !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query(['token' => $token]);
        }

        unset($response['data']['token']);

        return [
            'checkoutUrl' => $url,
            'sessionId' => $data['sessionId'] ?? null,
            'rawResponse' => $response,
        ];
    }

    /**
     * A card payment for a real order: one hosted-checkout session, `reference` as the
     * correlator. The buyer is sent to `checkoutUrl`; the result arrives by callback and by
     * {@see self::verifyByReference()}.
     *
     * @param  array{reference: string, amount: float, currency?: string, description?: string, customer_name?: ?string, customer_email?: ?string}  $context
     * @return array{checkoutUrl: string, sessionId: ?string, status: string, provider: string, rawResponse: array<string, mixed>}
     */
    public function initiateCardPayment(array $context): array
    {
        $session = $this->createCheckoutSession($context);

        $url = $session['checkoutUrl'];
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Card payments are unavailable right now. Please try another payment method.', 502);
        }

        return [
            'checkoutUrl' => $url,
            'sessionId' => $session['sessionId'],
            'status' => 'pending',
            'provider' => 'astragate',
            'rawResponse' => $session['rawResponse'],
        ];
    }

    /**
     * Reads a payment's status from Astragate.
     *
     * The status response is not documented to carry an amount or currency. When it does not,
     * `$expectedAmount` / `$expectedCurrency` (what *we* put in the checkout session, which the
     * customer cannot edit on the hosted page) stand in, so the settlement check still compares
     * a real figure instead of failing every payment. If Astragate does return them, those win.
     *
     * @return array<string, mixed>
     */
    public function verifyByReference(string $correlatorId, ?float $expectedAmount = null, ?string $expectedCurrency = null): array
    {
        $response = $this->request('GET', '/v1/payment/status/'.rawurlencode($correlatorId));
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $code = $data['statusCode'] ?? null;
        $status = self::mapStatusCode($code);

        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (float) $data['amount'] : $expectedAmount;
        $currency = isset($data['currency']) && is_string($data['currency']) && $data['currency'] !== ''
            ? $data['currency']
            : $expectedCurrency;

        return [
            'transactionId' => $data['astragateTransactionId'] ?? $data['systemTransactionId'] ?? null,
            'reference' => $correlatorId,
            'providerStatus' => (string) ($code ?? $status),
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'rawResponse' => $response,
        ];
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post(rtrim((string) config('astragate.auth_url'), '/').'/v1/auth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('astragate.client_id'),
                'client_secret' => config('astragate.client_secret'),
            ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Could not authenticate with Astragate', $response->status() ?: 502);
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = rtrim((string) config('astragate.base_url'), '/').$path;

        $send = fn () => Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->send($method, $url, strtoupper($method) === 'GET' ? [] : ['json' => $payload]);

        $response = $send();

        // A token revoked or expired early: drop the cache and retry once.
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $send();
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('Invalid response from Astragate', $response->status() ?: 502);
        }

        if (! $response->successful() || ($body['success'] ?? true) === false) {
            throw new RuntimeException((string) ($body['message'] ?? 'Astragate request failed'), $response->status() ?: 422);
        }

        return $body;
    }
}
