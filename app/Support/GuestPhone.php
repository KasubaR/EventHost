<?php

namespace App\Support;

/**
 * The one definition of a guest's phone number: what may be saved, when two numbers are the same person, and which
 * digits a wa.me link dials. Every guest-adding path (host form and API, import, open RSVP, group link) validates
 * through problem(), and Guest::phoneAlreadyUsed() compares through key(), so they cannot disagree.
 *
 * Accepted: a Zambian number (0971234567, +260 97 123 4567, 971234567; mobile 7x/9x or landline 2x), or an
 * international number written with its country code (+44 7700 900123, or 00 44 ...). Rows saved before this rule
 * existed may hold anything; key() and whatsAppDigits() tolerate them.
 */
final class GuestPhone
{
    public const MULTIPLE_MESSAGE = 'Enter one phone number only.';

    public const CHARACTERS_MESSAGE = 'Use digits only, for example 0971234567 or +260971234567.';

    public const ZAMBIAN_MESSAGE = 'That does not look like a Zambian number. Use 10 digits starting with 0, for example 0971234567.';

    public const INTERNATIONAL_MESSAGE = 'An international number needs its country code and 8 to 15 digits, for example +447700900123.';

    public const UNKNOWN_MESSAGE = 'Enter a Zambian number like 0971234567, or an international number starting with +.';

    /** Why $value cannot be saved as a guest's phone, or null when it can. */
    public static function problem(string $value): ?string
    {
        $value = trim($value);

        // Digits on both sides of a separator: "0971111111 / 0972222222", "097... or 096...".
        if (preg_match('/\d\D*(?:[\/,;|&]|\bor\b|\band\b)\D*\d/iu', $value) === 1) {
            return self::MULTIPLE_MESSAGE;
        }

        if (preg_match('/^\+?[\d\s().\-]+$/', $value) !== 1) {
            return self::CHARACTERS_MESSAGE;
        }

        $digits = self::digits($value);

        if (strlen($digits) > 15) {
            return self::MULTIPLE_MESSAGE;
        }

        return match (self::kind($value)) {
            'zambian' => self::zambianLocal($digits) !== null ? null : self::ZAMBIAN_MESSAGE,
            'international' => self::internationalDigits($value) !== null ? null : self::INTERNATIONAL_MESSAGE,
            default => self::UNKNOWN_MESSAGE,
        };
    }

    /**
     * What two numbers must share to be the same guest: "260" + the subscriber number for a Zambian number in any
     * form, the full digits otherwise. Null when there are no digits, so a blank number never matches another.
     */
    public static function key(?string $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        $digits = self::digits($phone);
        if ($digits === '') {
            return null;
        }

        if (self::kind($phone) === 'zambian' && ($local = self::zambianLocal($digits)) !== null) {
            return '260'.$local;
        }

        return self::internationalDigits($phone) ?? $digits;
    }

    /** Country code + number for wa.me, or null when the number cannot be dialled from anywhere. */
    public static function whatsAppDigits(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $digits = self::digits($phone);

        if (self::kind($phone) === 'zambian') {
            $local = self::zambianLocal($digits);

            return $local !== null ? '260'.$local : null;
        }

        return self::internationalDigits($phone);
    }

    /** 'zambian' | 'international' | 'unknown', read from how the number is written. */
    private static function kind(string $phone): string
    {
        $trimmed = ltrim($phone);
        $digits = self::digits($phone);

        if (str_starts_with($trimmed, '+') || str_starts_with($digits, '00')) {
            return str_starts_with(ltrim($digits, '0'), '260') ? 'zambian' : 'international';
        }

        if (str_starts_with($digits, '260') || str_starts_with($digits, '0') || strlen($digits) === 9) {
            return 'zambian';
        }

        return 'unknown';
    }

    /** The 9-digit subscriber number, or null when it is the wrong length or not a mobile/landline prefix. */
    private static function zambianLocal(string $digits): ?string
    {
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '260')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[279]\d{8}$/', $digits) === 1 ? $digits : null;
    }

    /** The digits after "+" or "00", when they are a plausible E.164 number (8 to 15 digits). */
    private static function internationalDigits(string $phone): ?string
    {
        $trimmed = ltrim($phone);
        $digits = self::digits($phone);

        if (str_starts_with($digits, '00') && ! str_starts_with($trimmed, '+')) {
            $digits = substr($digits, 2);
        } elseif (! str_starts_with($trimmed, '+')) {
            return null;
        }

        $length = strlen($digits);

        return $length >= 8 && $length <= 15 && ! str_starts_with($digits, '0') ? $digits : null;
    }

    private static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
