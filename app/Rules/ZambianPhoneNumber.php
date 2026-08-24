<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A general "is this plausibly a Zambian phone number" check for contact
 * fields (profile, registration) — deliberately looser than
 * ZambiaMobileMoneyPhone, which is operator-bound (MTN/Airtel prefix match)
 * and only fits a checkout form where the buyer has already picked a
 * network. This one accepts any Zambian mobile or landline shape without
 * pinning it to a specific carrier.
 */
class ZambianPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail('Please enter a valid phone number.');

            return;
        }

        $local = $this->localDigits($value);

        if (strlen($local) !== 9) {
            $fail('Please enter a valid Zambian phone number.');

            return;
        }

        // Mobile numbers start 7x/9x (MTN, Airtel, Zamtel); landlines start
        // 2x (e.g. 021 Lusaka). Not an exhaustive carrier list on purpose —
        // this only needs to catch numbers that clearly aren't Zambian.
        if (! preg_match('/^[729]/', $local)) {
            $fail('Please enter a valid Zambian phone number.');
        }
    }

    private function localDigits(string $phone): string
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
