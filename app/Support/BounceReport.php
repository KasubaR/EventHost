<?php

namespace App\Support;

/**
 * Reads one message from the bounce mailbox (plans/email-gaps.md Phase 3). Understands the standard delivery status
 * notification (RFC 3464 `message/delivery-status`), which Exim (cPanel), Gmail, Outlook and Yahoo all send, and the
 * X-EventHost-Ref header from the original message's headers they quote back. Anything else (an auto-reply, an
 * out-of-office, spam) is not a bounce and reads as null.
 *
 * Nothing here logs or keeps the raw message. The diagnostic kept is one line with any address in it replaced.
 */
final class BounceReport
{
    private function __construct(
        public readonly string $recipient,
        public readonly string $status,
        public readonly ?string $diagnostic,
        public readonly ?string $ref,
    ) {}

    public static function parse(string $raw): ?self
    {
        $text = self::unfold(str_replace(["\r\n", "\r"], "\n", $raw));

        $recipient = self::field($text, 'Final-Recipient') ?? self::field($text, 'Original-Recipient');
        if ($recipient === null) {
            return null;
        }

        // "rfc822; name@example.com" — the address type comes first.
        $recipient = trim((string) preg_replace('/^[a-z0-9-]+\s*;\s*/i', '', $recipient), " \t<>");
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) && ! str_contains($recipient, '@')) {
            return null;
        }

        $status = null;
        if (preg_match('/^Status:\s*([245]\.\d{1,3}\.\d{1,3})/mi', $text, $m) === 1) {
            $status = $m[1];
        } elseif (preg_match('/^Action:\s*failed/mi', $text) === 1) {
            $status = '5.0.0';
        } elseif (preg_match('/^Action:\s*delayed/mi', $text) === 1) {
            $status = '4.0.0';
        }

        if ($status === null || str_starts_with($status, '2.')) {
            return null;
        }

        $diagnostic = self::cleanDiagnostic(self::field($text, 'Diagnostic-Code'));

        // Exim reports a generic 5.0.0 and leaves the real code ("550 5.1.1 User unknown", "550 5.7.1 spam") in the diagnostic.
        if (str_ends_with($status, '.0.0') && $diagnostic !== null
            && preg_match('/\b('.$status[0].'\.\d{1,3}\.\d{1,3})\b/', $diagnostic, $m) === 1) {
            $status = $m[1];
        }

        return new self(
            recipient: mb_strtolower($recipient),
            status: $status,
            diagnostic: $diagnostic,
            ref: self::field($text, 'X-EventHost-Ref'),
        );
    }

    /**
     * A permanent failure that says the address itself is no good. A full mailbox (5.2.2) and a policy refusal (5.7.x, which is
     * about the sender or the content, not the address) are permanent for that message but are no reason to stop writing.
     */
    public function isHard(): bool
    {
        return str_starts_with($this->status, '5.')
            && $this->status !== '5.2.2'
            && ! str_starts_with($this->status, '5.7.');
    }

    private static function unfold(string $text): string
    {
        return (string) preg_replace('/\n[ \t]+/', ' ', $text);
    }

    private static function field(string $text, string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/mi', $text, $m) !== 1) {
            return null;
        }

        $value = trim($m[1]);

        return $value === '' ? null : $value;
    }

    private static function cleanDiagnostic(?string $diagnostic): ?string
    {
        if ($diagnostic === null) {
            return null;
        }

        $diagnostic = (string) preg_replace('/^smtp;\s*/i', '', $diagnostic);
        $diagnostic = (string) preg_replace('/<?[^\s<>@]+@[^\s<>]+>?/', '[address]', $diagnostic);

        return mb_substr(trim($diagnostic), 0, 190) ?: null;
    }
}
