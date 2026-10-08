<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The one rule for "how many people are coming", used by the personal link, the open form, the group link and the host's
 * override, so every channel gives the same sentence for the same mistake. plans/rsvp-attendance.md Phase 1.
 *
 * It runs only for an acceptance: a Declined or Maybe answer ignores whatever count was posted (a form with no JavaScript
 * posts the default). A value is a whole number written in digits, optionally signed and padded with spaces; anything else
 * (2.5, 2.0, 1e3, "two", an array) is refused rather than guessed at.
 */
class AttendeeCount implements ValidationRule
{
    /** A count longer than this is not a seat count; it is refused before it is ever cast to an integer. */
    private const MAX_DIGITS = 4;

    /**
     * @param  int  $max  The most this guest may RSVP for, themselves included.
     * @param  Closure(): bool  $accepting  Whether the answer being validated is an acceptance.
     */
    public function __construct(private readonly int $max, private readonly Closure $accepting) {}

    /**
     * The count as an integer, or null when it is not a whole number in digits.
     */
    public static function parse(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^\s*([+-]?)(\d{1,'.self::MAX_DIGITS.'})\s*$/', $value, $m) !== 1) {
            return null;
        }

        return $m[1] === '-' ? -(int) $m[2] : (int) $m[2];
    }

    /**
     * What to say when a whole number is outside 1..$max. Also used by the service, which re-checks.
     */
    public static function outOfRangeMessage(int $count, int $max): string
    {
        if ($count < 1) {
            return 'Choose at least 1, or answer "Not attending" instead.';
        }

        return $max <= 1
            ? 'You can RSVP for yourself only.'
            : "You can RSVP for at most {$max} people, including yourself.";
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ($this->accepting)()) {
            return;
        }

        $count = self::parse($value);

        if ($count === null) {
            // Digits, but too many of them: a number nobody can seat, so say what the limit is.
            $fail(is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1
                ? self::outOfRangeMessage($this->max + 1, $this->max)
                : 'Please choose how many people are coming.');

            return;
        }

        if ($count < 1 || $count > $this->max) {
            $fail(self::outOfRangeMessage($count, $this->max));
        }
    }
}
