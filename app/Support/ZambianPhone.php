<?php

namespace App\Support;

/**
 * E.164 formatting for the Twilio WhatsApp integration (plans/whatsapp-invitations.md).
 *
 * The "strip to 9 local digits" logic here is deliberately the same as
 * EventContribution::normalizePhone() / App\Rules\ZambianPhoneNumber / App\Rules\ZambiaMobileMoneyPhone
 * — this is a new, narrowly-scoped helper rather than a refactor of those three call sites, so it
 * doesn't touch already-working validation/lookup code. A future cleanup could have all four share
 * one implementation.
 */
final class ZambianPhone
{
    /**
     * True when $phone has exactly 9 local digits once country/trunk prefixes are stripped — the
     * shape every Zambian mobile number normalizes to. Numbers that don't fit (a mistyped number, a
     * non-Zambian one) should disable WhatsApp-send UI rather than pass a malformed value to Twilio.
     */
    public static function isValid(?string $phone): bool
    {
        return is_string($phone) && strlen(self::localDigits($phone)) === 9;
    }

    /**
     * @return non-empty-string|null null when $phone doesn't normalize to a plausible Zambian number.
     */
    public static function toE164(?string $phone): ?string
    {
        if (! self::isValid($phone)) {
            return null;
        }

        return '+260'.self::localDigits($phone);
    }

    private static function localDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '260')) {
            return substr($digits, 3);
        }

        if (str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        return $digits;
    }
}
