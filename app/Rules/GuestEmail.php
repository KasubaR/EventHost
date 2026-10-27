<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sits after `email:rfc` on every guest email field. RFC 5322 allows a domain with no dot (`name@localhost`, `name@gmail`),
 * which no real guest has and which only ever ends as a failed send. Not a deliverability check: there is no DNS lookup.
 */
class GuestEmail implements ValidationRule
{
    public const MESSAGE = 'Enter the full email address, like name@example.com.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (! self::hasDottedDomain($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function hasDottedDomain(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = trim(substr($email, $at + 1));

        return $domain !== ''
            && str_contains($domain, '.')
            && ! str_starts_with($domain, '.')
            && ! str_ends_with($domain, '.')
            && ! str_contains($domain, '..');
    }
}
