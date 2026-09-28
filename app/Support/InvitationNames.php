<?php

namespace App\Support;

/**
 * Splits an event name into the two names a couple-style hero prints on either side of its
 * "&" / "and" ornament — "Kasuba & Tamara" becomes ["Kasuba", "Tamara"].
 *
 * Separators are tried in order: " for ", then "&", then " and " (case-insensitive). The second
 * name is '' when there is no separator, or when either side of it would be empty, so a caller
 * only needs to check the second part to decide between one line and two.
 */
final class InvitationNames
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        $name = trim($name);

        $forPos = stripos($name, ' for ');
        if ($forPos !== false) {
            return self::pair(substr($name, 0, $forPos), substr($name, $forPos + 5), $name);
        }

        $ampersandPos = strpos($name, '&');
        if ($ampersandPos !== false) {
            return self::pair(substr($name, 0, $ampersandPos), substr($name, $ampersandPos + 1), $name);
        }

        $parts = preg_split('/\s+and\s+/i', $name, 2);
        if (is_array($parts) && count($parts) === 2) {
            return self::pair($parts[0], $parts[1], $name);
        }

        return [$name, ''];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function pair(string $first, string $second, string $whole): array
    {
        $first = trim($first);
        $second = trim($second);

        return ($first === '' || $second === '') ? [$whole, ''] : [$first, $second];
    }
}
