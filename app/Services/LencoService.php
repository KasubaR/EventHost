<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LencoService
{
    private string $baseUrl;

    private ?string $apiSecretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.lenco.base_url'), '/');
        $this->apiSecretKey = config('services.lenco.api_secret_key');
    }

    public function generatePaymentReference(int $userId): string
    {
        $timestamp = now()->timestamp;
        $random = bin2hex(random_bytes(4));

        return "EH-{$userId}-{$timestamp}-{$random}";
    }

    public static function mapStatus(string $lencoStatus): string
    {
        return match (strtolower(trim($lencoStatus))) {
            'successful', 'success', 'succeeded', 'paid', 'completed', 'complete' => 'completed',
            'processing' => 'processing',
            'failed' => 'failed',
            'cancelled', 'canceled', 'expired' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * @param  array{ref: string, amount: float, description?: string}  $context
     * @return array<string, mixed>
     */
    public function initiateMobileMoneyPayment(array $context, string $phone, string $operator): array
    {
        $reference = $this->generatePaymentReference((int) ($context['user_id'] ?? 0));
        if (isset($context['reference'])) {
            $reference = $context['reference'];
        }

        $payload = [
            'phone' => $this->normalizeZambiaPhone($phone),
            'operator' => strtolower($operator),
            'amount' => round((float) $context['amount'], 2),
            'currency' => $context['currency'] ?? 'ZMW',
            'reference' => $reference,
            'country' => 'ZM',
            'description' => $context['description'] ?? 'Event Host payment',
        ];

        $response = $this->requestWithRetry('POST', '/collections/mobile-money', $payload);
        $data = $this->unwrapData($response);

        return [
            'success' => true,
            'transactionId' => $data['id'] ?? $data['transactionId'] ?? null,
            'reference' => $reference,
            'lencoReference' => $data['lencoReference'] ?? $data['lenco_reference'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'amount' => $data['amount'] ?? $payload['amount'],
            'currency' => $data['currency'] ?? $payload['currency'],
            'provider' => strtolower($operator),
            'paymentInstructions' => $data['paymentInstructions'] ?? $data['message'] ?? null,
            'mobileMoneyDetails' => $data['mobileMoneyDetails'] ?? null,
            'initiatedAt' => $data['initiatedAt'] ?? now()->toIso8601String(),
            'rawResponse' => $response,
        ];
    }

    /**
     * @param  array{ref: string, amount: float, description?: string, reference?: string, currency?: string}  $context
     * @param  array{bankName: string}  $bank
     * @return array<string, mixed>
     */
    public function initiateBankTransfer(array $context, array $bank): array
    {
        $reference = $context['reference'] ?? $this->generatePaymentReference((int) ($context['user_id'] ?? 0));

        $payload = [
            'amount' => round((float) $context['amount'], 2),
            'currency' => $context['currency'] ?? 'ZMW',
            'reference' => $reference,
            'country' => 'ZM',
            'description' => $context['description'] ?? 'Event Host payment',
            'bankName' => $bank['bankName'],
        ];

        $response = $this->requestWithRetry('POST', '/collections/bank-transfer', $payload);
        $data = $this->unwrapData($response);

        return [
            'success' => true,
            'transactionId' => $data['id'] ?? $data['transactionId'] ?? null,
            'reference' => $reference,
            'lencoReference' => $data['lencoReference'] ?? $data['lenco_reference'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'amount' => $data['amount'] ?? $payload['amount'],
            'currency' => $data['currency'] ?? $payload['currency'],
            'bankDetails' => $data['bankDetails'] ?? $data['bank_details'] ?? null,
            'paymentUrl' => $data['paymentUrl'] ?? $data['payment_url'] ?? null,
            'rawResponse' => $response,
        ];
    }

    /**
     * @return array<int, array{name: string, id?: string|null}>
     */
    public function getBanks(): array
    {
        $response = $this->requestWithRetry('GET', '/banks', ['country' => 'zm']);
        $data = $this->unwrapData($response);

        if (isset($data['banks']) && is_array($data['banks'])) {
            return $this->normalizeBankList($data['banks']);
        }

        if (is_array($data) && ($data === [] || array_is_list($data))) {
            return $this->normalizeBankList($data);
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $banks
     * @return array<int, array{name: string, id?: string|null}>
     */
    private function normalizeBankList(array $banks): array
    {
        $normalized = [];

        foreach ($banks as $bank) {
            if (is_string($bank) && trim($bank) !== '') {
                $normalized[] = ['name' => trim($bank)];

                continue;
            }

            if (! is_array($bank)) {
                continue;
            }

            $name = $bank['name']
                ?? $bank['bankName']
                ?? $bank['bank_name']
                ?? $bank['title']
                ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $entry = ['name' => trim($name)];

            if (isset($bank['id']) && (is_string($bank['id']) || is_int($bank['id']))) {
                $entry['id'] = (string) $bank['id'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyPayment(string $transactionId): array
    {
        $response = $this->requestWithRetry('GET', '/collections/'.urlencode($transactionId));

        return $this->normalizeVerificationResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyByReference(string $reference): array
    {
        $response = $this->requestWithRetry('GET', '/collections/status/'.urlencode($reference));

        return $this->normalizeVerificationResponse($response);
    }

    public function validateWebhookSignature(string $rawBody, string $signature): bool
    {
        if ($this->apiSecretKey === null || $this->apiSecretKey === '') {
            return false;
        }

        $override = config('services.lenco.webhook_secret');
        $hashKey = is_string($override) && $override !== ''
            ? $override
            : hash('sha256', $this->apiSecretKey);

        $signature = strtolower(trim($signature));
        if (str_starts_with($signature, 'sha512=')) {
            $signature = substr($signature, 7);
        }

        $expected = hash_hmac('sha512', $rawBody, $hashKey);

        return hash_equals($expected, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function extractWebhookData(string $rawBody): array
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Invalid webhook payload', 400);
        }

        $data = $payload['data'] ?? $payload;

        if (! is_array($data)) {
            throw new RuntimeException('Invalid webhook data', 400);
        }

        return [
            'status' => $payload['status'] ?? $data['status'] ?? null,
            'transactionId' => $data['id'] ?? $data['transactionId'] ?? null,
            'reference' => $data['reference'] ?? null,
            'lencoReference' => $data['lencoReference'] ?? $data['lenco_reference'] ?? null,
            'lencoStatus' => $data['status'] ?? null,
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'raw' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handleWebhook(string $rawBody): array
    {
        return $this->extractWebhookData($rawBody);
    }

    public function normalizeZambianPhone(string $phone): string
    {
        return $this->normalizeZambiaPhone($phone);
    }

    public function normalizeZambiaPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '260')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0')) {
            return '+260'.substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '+260'.$digits;
        }

        return $phone;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function requestWithRetry(string $method, string $path, array $payload = [], ?string $baseUrl = null): array
    {
        if ($this->apiSecretKey === null || $this->apiSecretKey === '') {
            throw new RuntimeException('Lenco API secret key is not configured', 401);
        }

        $url = rtrim($baseUrl ?? $this->baseUrl, '/').'/'.ltrim($path, '/');

        $request = Http::withToken($this->apiSecretKey)
            ->acceptJson()
            ->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $payload),
            'POST' => $request->post($url, $payload),
            'DELETE' => $request->delete($url, $payload),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}", 400),
        };

        $status = $response->status();
        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Invalid response from Lenco', $status ?: 0);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                $body['message'] ?? 'Lenco API request failed',
                $status
            );
        }

        if (array_key_exists('status', $body) && $body['status'] === false) {
            throw new RuntimeException(
                $body['message'] ?? 'Lenco business logic error',
                $status ?: 422
            );
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function normalizeVerificationResponse(array $response): array
    {
        $data = $this->unwrapData($response);
        $lencoStatus = (string) ($data['status'] ?? 'pending');

        return [
            'transactionId' => $data['id'] ?? $data['transactionId'] ?? null,
            'reference' => $data['reference'] ?? null,
            'lencoReference' => $data['lencoReference'] ?? $data['lenco_reference'] ?? null,
            'lencoStatus' => $lencoStatus,
            'status' => self::mapStatus($lencoStatus),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
            'currency' => $data['currency'] ?? null,
            'rawResponse' => $response,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function unwrapData(array $response): array
    {
        $data = $response['data'] ?? $response;

        return is_array($data) ? $data : [];
    }
}
