<?php

namespace App\Support;

/**
 * Shared length gate for the scanner's manual lookup. Guest index search
 * still treats a blank `q` as "no filter"; these endpoints must not.
 */
final class CheckInLookup
{
    public const MIN_TERM_LENGTH = 2;

    public static function term(?string $raw): ?string
    {
        $term = is_string($raw) ? trim($raw) : '';

        if ($term === '' || mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return null;
        }

        return $term;
    }
}
