<?php

namespace App\Support;

/**
 * Cleans a personal-link token from a URL before it is looked up. A link pasted into WhatsApp or an email
 * often arrives with a trailing `.`, `)` or `>`, a space (`%20`) or stray quotes; none of that is part of the
 * token. Real tokens are `Str::random(48)`, i.e. letters and digits. plans/rsvp-token-edge-cases.md T2.
 *
 * Case is never changed: tokens match exactly.
 */
final class GuestLinkToken
{
    private const MAX_LENGTH = 64;

    /** Whitespace and the punctuation that clings to a pasted link. */
    private const WRAPPERS = " \t\r\n\0\x0B.,;:!?)]}>\"'<([{";

    /**
     * @return string|null the token to look up, or null when nothing a token could be (404 without a query)
     */
    public static function clean(string $raw): ?string
    {
        $token = trim($raw, self::WRAPPERS);

        if ($token === '' || strlen($token) > self::MAX_LENGTH || preg_match('/\A[A-Za-z0-9_-]+\z/', $token) !== 1) {
            return null;
        }

        return $token;
    }
}
