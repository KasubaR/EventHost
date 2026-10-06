<?php

namespace App\Support;

/**
 * How long a host's text is, as a class the guest stylesheet can react to. The name field allows 255 characters, which no layout is
 * designed for: wrapping is global (events-invitation.css), and headings step down for a long name (`evt-name--long`,
 * `evt-name--xlong`). One definition, so the thresholds are not repeated in views.
 */
final class InvitationTextLength
{
    public const LONG = 40;

    public const EXTRA_LONG = 80;

    public static function nameClass(?string $name): string
    {
        $length = mb_strlen(trim((string) $name));

        return match (true) {
            $length > self::EXTRA_LONG => 'evt-name--xlong',
            $length > self::LONG => 'evt-name--long',
            default => '',
        };
    }
}
