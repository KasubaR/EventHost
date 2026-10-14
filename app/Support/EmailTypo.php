<?php

namespace App\Support;

/**
 * A "did you mean" for an email address whose domain is one typo away from a big mailbox provider (`john@gmial.com`,
 * `john@gmail`, `john@hotmal.com`). The address is still saved as typed (it may be right); this only lets the host fix a slip
 * before every email to that guest silently fails. plans/guest-adding-gaps.md Phase 3.
 */
final class EmailTypo
{
    /** Providers worth correcting towards. Long enough that a one-letter difference is a slip, not another company. */
    private const PROVIDERS = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com'];

    /** Real domains close to a provider that must never be "corrected". */
    private const REAL = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.co.za', 'yahoo.fr', 'ymail.com', 'outlook.com',
        'outlook.co.za', 'hotmail.com', 'hotmail.co.uk', 'hotmail.fr', 'icloud.com', 'live.com', 'live.co.uk', 'me.com', 'msn.com',
        'mail.com', 'aol.com', 'zoho.com', 'proton.me', 'protonmail.com',
    ];

    /** The corrected address, or null when the domain is fine, unknown, or not close to a provider. */
    public static function suggest(?string $email): ?string
    {
        if (! is_string($email) || substr_count($email, '@') !== 1) {
            return null;
        }

        [$local, $domain] = explode('@', strtolower(trim($email)));

        if ($local === '' || $domain === '' || in_array($domain, self::REAL, true)) {
            return null;
        }

        $best = null;
        $bestDistance = 3;

        foreach (self::PROVIDERS as $provider) {
            // "john@gmail": the whole name, no ending.
            if ($domain === strstr($provider, '.', true)) {
                return $local.'@'.$provider;
            }

            $distance = levenshtein($domain, $provider);

            if ($distance >= 1 && $distance <= 2 && $distance < $bestDistance) {
                $best = $provider;
                $bestDistance = $distance;
            }
        }

        return $best === null ? null : $local.'@'.$best;
    }
}
