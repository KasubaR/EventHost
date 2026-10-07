<?php

namespace App\Support;

/**
 * Zambian phone number normalisation, shared by every payment gateway so none of
 * them has to reach into another's service for it.
 */
final class ZambiaPhone
{
    /** Returns `+260…` for anything recognisable, or the input untouched. */
    public static function normalize(string $phone): string
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
}
