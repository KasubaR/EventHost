<?php

namespace App\Support;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $event, array $context = []): void
    {
        self::write('info', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $event, array $context = []): void
    {
        self::write('warning', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $event, array $context = []): void
    {
        self::write('error', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function forPayment(Payment $payment, string $event, array $context = [], string $level = 'info'): void
    {
        self::write($level, $event, array_merge(self::paymentContext($payment), $context));
    }

    /**
     * @return array<string, mixed>
     */
    public static function paymentContext(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'payment_reference' => $payment->payment_reference,
            'status' => $payment->status,
            'payment_method' => $payment->payment_method,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function write(string $level, string $event, array $context): void
    {
        $payload = array_merge(['event' => $event], self::sanitize($context));

        Log::channel('payments')->{$level}($event, $payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function sanitize(array $context): array
    {
        unset($context['api_secret_key'], $context['webhook_payload'], $context['raw_body']);

        if (isset($context['phone']) && is_string($context['phone'])) {
            $context['phone'] = self::maskPhone($context['phone']);
        }

        return $context;
    }

    private static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) < 4) {
            return '***';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
