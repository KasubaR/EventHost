<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Astragate collections (https://docs.astragate.africa). Sandbox-first: the
 * base URLs come from config so go-live is an env change.
 *
 * Astragate callbacks carry no signature, so the callback URL itself is the
 * secret — see `AstragateWebhookController` and `services.astragate.webhook_secret`.
 */
class AstragateService
{
    private const TOKEN_CACHE_KEY = 'astragate.access_token';

    public static function configured(): bool
    {
        return filled(config('services.astragate.client_id'))
            && filled(config('services.astragate.client_secret'));
    }

    /**
     * Maps an Astragate status code to the app's payment statuses
     * (same vocabulary as {@see LencoService::mapStatus()}).
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
     * @param  array{reference: string, amount: float, currency?: string, description?: string}  $context
     * @return array<string, mixed>
     */
    public function initiateCollection(array $context, string $phone): array
    {
        $payload = [
            'accountNumber' => ltrim($this->normalizePhone($phone), '+'),
            'correlatorId' => $context['reference'],
            'currency' => $context['currency'] ?? 'ZMW',
            'paymentDescription' => $context['description'] ?? 'Event Host payment',
            'amount' => round((float) $context['amount'], 2),
        ];

        $response = $this->request('POST', '/v1/payment/collection', $payload);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $code = $data['statusCode'] ?? null;

        return [
            'transactionId' => $data['astragateTransactionId'] ?? null,
            'reference' => $context['reference'],
            'status' => self::mapStatusCode($code),
            'statusCode' => $code,
            'paymentInstructions' => $data['statusDescription'] ?? null,
            'rawResponse' => $response,
        ];
    }

    /**
     * @return array<string, mixed>  shaped like {@see LencoService::verifyByReference()}
     */
    public function verifyByReference(string $correlatorId): array
    {
        $response = $this->request('GET', '/v1/payment/status/'.rawurlencode($correlatorId));
        $data = is_array($response['data'] ?? null) ? $response['data'] : $response;
        $code = $data['statusCode'] ?? null;
        $status = self::mapStatusCode($code);

        return [
            'transactionId' => $data['astragateTransactionId'] ?? $data['systemTransactionId'] ?? null,
            'reference' => $correlatorId,
            'lencoStatus' => (string) ($code ?? $status),
            'status' => $status,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
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
            ->post(rtrim((string) config('services.astragate.auth_url'), '/').'/v1/auth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.astragate.client_id'),
                'client_secret' => config('services.astragate.client_secret'),
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
        $url = rtrim((string) config('services.astragate.base_url'), '/').$path;

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

    private function normalizePhone(string $phone): string
    {
        return app(LencoService::class)->normalizeZambiaPhone($phone);
    }
}
