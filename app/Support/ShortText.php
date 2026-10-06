<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Host text (an event name, a venue) can be 255 characters long. Channels with a short window for it, an email subject line or a WhatsApp
 * template variable, take it from here so the limit is one number per channel rather than a `Str::limit` scattered through each sender.
 * The full text is always still in the message body or on the page.
 */
final class ShortText
{
    /** An email subject longer than this is folded across lines by mail servers and cut off in most inboxes. */
    public const SUBJECT = 60;

    /** A WhatsApp template variable has to stay on one line; this keeps the filled-in message readable. */
    public const WHATSAPP = 100;

    /** One line, whitespace collapsed (WhatsApp rejects tabs, newlines and long runs of spaces in a variable), cut with an ellipsis. */
    public static function limit(?string $text, int $max): string
    {
        $oneLine = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        if (mb_strlen($oneLine) <= $max) {
            return $oneLine;
        }

        // The ellipsis counts toward the limit, so the result is never longer than $max.
        return rtrim(mb_substr($oneLine, 0, $max - 1)).'…';
    }

    public static function subject(?string $text): string
    {
        return self::limit($text, self::SUBJECT);
    }

    public static function whatsapp(?string $text): string
    {
        return self::limit($text, self::WHATSAPP);
    }
}
