<?php

namespace App\Support;

use App\Models\EmailSuppression;
use App\Models\Guest;
use App\Models\NotificationLog;

/**
 * Counts the guests a bulk email action (web and API) left out, by reason, so the host is told why rather than just how many.
 * plans/email-gaps.md Phases 3 and 4.
 */
class EmailSkips
{
    public const NO_EMAIL = 'no email address';

    public const BOUNCED = 'email bounced';

    public const SAME_UPDATE = 'already sent this message in the last 10 minutes';

    /** How long the same update text is treated as a repeat (a double submit, or Back then send again). */
    public const SAME_UPDATE_MINUTES = 10;

    /** @var array<string, int> */
    private array $counts = [];

    /** True (and counted) when the guest has no address, or theirs hard-bounced. */
    public function unreachable(Guest $guest): bool
    {
        if (blank($guest->email)) {
            return $this->skip(self::NO_EMAIL);
        }

        if (EmailSuppression::isSuppressed($guest->email)) {
            return $this->skip(self::BOUNCED);
        }

        return false;
    }

    /** True (and counted) when this guest was sent this exact update text within the last 10 minutes. */
    public function sameUpdateRecently(Guest $guest, string $message): bool
    {
        $repeat = NotificationLog::query()
            ->where('guest_id', $guest->id)
            ->where('channel', 'email')
            ->where('type', 'event_update')
            ->where('status', '!=', NotificationLog::STATUS_FAILED)
            ->where('created_at', '>=', now()->subMinutes(self::SAME_UPDATE_MINUTES))
            ->where('meta->message_hash', self::messageHash($message))
            ->exists();

        return $repeat ? $this->skip(self::SAME_UPDATE) : false;
    }

    public static function messageHash(string $message): string
    {
        return sha1(trim($message));
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    /** "2 have no email address, 1 email bounced", or null when nobody was skipped. */
    public function summary(): ?string
    {
        if ($this->counts === []) {
            return null;
        }

        return collect($this->counts)
            ->map(fn (int $n, string $reason) => $n.' '.($reason === self::NO_EMAIL ? ($n === 1 ? 'has ' : 'have ').$reason : $reason))
            ->implode(', ');
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    private function skip(string $reason): bool
    {
        $this->counts[$reason] = ($this->counts[$reason] ?? 0) + 1;

        return true;
    }
}
