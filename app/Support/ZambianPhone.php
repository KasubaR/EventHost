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
     * True when $phone is a Zambian mobile number: 9 local digits starting 7 or 9, once country/trunk prefixes are stripped.
     * (It used to accept any nine digits, which let a landline through to Twilio.) Numbers that don't fit (a mistyped number, a
     * non-Zambian one) should disable WhatsApp-send UI rather than pass a malformed value to Twilio.
     */
    public static function isValid(?string $phone): bool
    {
        // Mobile prefixes only (7x and 9x): WhatsApp cannot reach a landline (2x), and no other 9-digit start is a Zambian number.
        return is_string($phone) && preg_match('/^[79]\d{8}$/', self::localDigits($phone)) === 1;
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
