<?php

namespace App\Support;

/**
 * Makes a value safe to put in a CSV that a host will open in Excel or Sheets. A guest types their own name and message on the
 * open RSVP form, so a cell that starts with `=`, `+`, `-` or `@` could run as a formula (`=HYPERLINK(...)`) on the host's
 * machine. Such a cell gets a leading apostrophe, which spreadsheets show as plain text. plans/guest-adding-gaps.md Phase 1.
 */
final class SpreadsheetCell
{
    /** Written first in a CSV so Excel reads it as UTF-8 (without it, accents and non-Latin names turn into mojibake). */
    public const BOM = "\xEF\xBB\xBF";

    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (! in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
            return $value;
        }

        // A phone number ("+260 97 123 4567") or a plain negative number can only hold digits, spaces and . ( ) -, which
        // cannot call a function or reach another file, so they stay exactly as written.
        if (in_array($value[0], ['+', '-'], true) && preg_match('/^[+-]?[\d\s().\-]*$/', $value) === 1) {
            return $value;
        }

        return "'".$value;
    }

    /**
     * @param  array<int|string, mixed>  $cells
     * @return array<int|string, mixed>
     */
    public static function row(array $cells): array
    {
        return array_map(self::safe(...), $cells);
    }
}
