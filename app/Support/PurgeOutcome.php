<?php

namespace App\Support;

/**
 * Result of EventPurgeService::purge() for one event.
 */
final class PurgeOutcome
{
    public const PURGED = 'purged';

    public const WOULD_PURGE = 'would_purge';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * @param  array<string, int>  $counts  Rows removed with the event (guests, rsvps, photos, …)
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $counts = [],
    ) {}

    /** @param array<string, int> $counts */
    public static function purged(array $counts): self
    {
        return new self(self::PURGED, null, $counts);
    }

    /** @param array<string, int> $counts */
    public static function wouldPurge(array $counts): self
    {
        return new self(self::WOULD_PURGE, null, $counts);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, $reason);
    }

    public function isPurged(): bool
    {
        return $this->status === self::PURGED;
    }
}
