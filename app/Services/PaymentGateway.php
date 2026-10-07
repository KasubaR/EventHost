<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\TicketPayment;

/**
 * The one place that decides which gateway to ask about a stored payment, so verify
 * endpoints, the pollers and reconciliation don't each repeat the Lenco/Astragate choice.
 * Rows from before gateways existed default to `lenco`.
 */
final class PaymentGateway
{
    public const LENCO = 'lenco';

    public const ASTRAGATE = 'astragate';

    public static function isAstragate(Payment|TicketPayment $payment): bool
    {
        return $payment->gateway === self::ASTRAGATE;
    }

    /**
     * Reads the payment's current status by its reference, from whichever gateway took it.
     * `$reference` overrides the stored one for callers that already hold the order reference
     * (a ticket payment's reference is its order's). Both return the same shape: `status` (mapped), `providerStatus`/`lencoStatus`, amount, currency.
     *
     * @return array<string, mixed>
     */
    public static function verifyByReference(Payment|TicketPayment $payment, ?string $reference = null): array
    {
        $reference ??= (string) $payment->payment_reference;

        if (self::isAstragate($payment)) {
            return app(AstragateService::class)->verifyByReference(
                $reference,
                (float) $payment->amount,
                (string) $payment->currency,
            );
        }

        return app(LencoService::class)->verifyByReference($reference);
    }
}
