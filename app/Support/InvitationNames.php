<?php

namespace App\Support;

/**
 * Splits an event name into the two names a couple-style hero prints on either side of its
 * "&" / "and" ornament — "Kasuba & Tamara" becomes ["Kasuba", "Tamara"].
 *
 * Separators are tried in order: " for ", then "&", then " and " (case-insensitive). The second
 * name is '' when there is no separator, or when either side of it would be empty, so a caller
 * only needs to check the second part to decide between one line and two.
 *
 * Only a short name is split: a long one ("Annual Leadership and Innovation Summit") is a title, not two people, and splitting it
 * would print an ampersand between two halves of a sentence. Longer than MAX_LENGTH characters, or a side of more than
 * MAX_WORDS_PER_SIDE words, stays on one line.
 */
final class InvitationNames
{
    public const MAX_LENGTH = 60;

    public const MAX_WORDS_PER_SIDE = 4;

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

        if ($first === '' || $second === '' || mb_strlen($whole) > self::MAX_LENGTH) {
            return [$whole, ''];
        }

        foreach ([$first, $second] as $side) {
            if (count(preg_split('/\s+/u', $side, -1, PREG_SPLIT_NO_EMPTY) ?: []) > self::MAX_WORDS_PER_SIDE) {
                return [$whole, ''];
            }
        }

        return [$first, $second];
    }
}
