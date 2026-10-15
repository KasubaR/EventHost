<?php

namespace App\Support;

/**
 * E.164 formatting for the Twilio WhatsApp integration (plans/whatsapp-invitations.md).
 *
 * Reads the number through GuestPhone::whatsAppDigits(), the same rule the free wa.me share link uses, so every way of
 * writing a Zambian mobile that the share link opens (`0971234567`, `+260 97 123 4567`, `00260 97…`, `+260 (0) 97…`) is
 * also one the automated send accepts. Only Zambian mobiles qualify: the templates and pricing are for one country.
 */
final class ZambianPhone
{
    /**
     * True when $phone is a Zambian mobile number (7x or 9x). A landline, an international number or one that cannot be
     * read should disable the WhatsApp-send UI rather than pass a malformed value to Twilio.
     */
    public static function isValid(?string $phone): bool
    {
        return self::localDigits($phone) !== null;
    }

    /**
     * @return non-empty-string|null null when $phone doesn't normalize to a Zambian mobile number.
     */
    public static function toE164(?string $phone): ?string
    {
        $local = self::localDigits($phone);

        return $local === null ? null : '+260'.$local;
    }

    /** True when the number is not Zambian but the share link can still open it. */
    public static function isInternationalForShare(?string $phone): bool
    {
        return ! self::isValid($phone) && GuestPhone::whatsAppDigits($phone) !== null;
    }

    private static function localDigits(?string $phone): ?string
    {
        $digits = GuestPhone::whatsAppDigits($phone);

        if ($digits === null || preg_match('/^260([79]\d{8})$/', $digits, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
