<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Carbon;

/**
 * What to tell a person about a deleted event's fate: how long until it is
 * permanently removed, or that it is being kept for payment records. One place,
 * so the host's Recently deleted list, the admin pages and the API cannot word
 * or date it differently. Plan: plans/event-retention.md §4.
 *
 * Null when there is nothing to say — the event isn't deleted, or purging is off
 * (EVENT_TRASH_RETENTION_DAYS=0), in which case a deleted event simply stays and
 * the UI shows what it always did.
 */
final class EventRetentionNotice
{
    public const COUNTDOWN = 'countdown';

    public const KEPT = 'kept';

    private function __construct(
        public readonly string $state,
        public readonly ?Carbon $purgeAt,
        public readonly string $label,
    ) {}

    public static function for(Event $event): ?self
    {
        if ($event->deleted_at === null || Event::retentionDays() < 1) {
            return null;
        }

        if ($event->hasRetainedFinancialRecords()) {
            return new self(self::KEPT, null, 'Kept for payment records');
        }

        $purgeAt = $event->scheduledPurgeDate();

        return $purgeAt === null ? null : new self(self::COUNTDOWN, $purgeAt, self::countdownLabel($purgeAt));
    }

    /**
     * The two additive fields both event API resources carry. `purge_at` is null when
     * the event will never be purged (live, purging off, or kept for payment records);
     * `retained_for_records` says which of those it is for a deleted event. No query
     * runs for a live event.
     *
     * @return array{purge_at: ?string, retained_for_records: bool}
     */
    public static function apiFields(Event $event): array
    {
        $notice = self::for($event);

        return [
            'purge_at' => $notice?->purgeAt?->toIso8601String(),
            'retained_for_records' => $notice?->isKept() ?? false,
        ];
    }

    public function isKept(): bool
    {
        return $this->state === self::KEPT;
    }

    /**
     * Whole days rounded *up*, so "1 day" means somewhere within the next 24 hours and
     * the label never reads 0 while the event is still restorable. Past due means the
     * next 03:00 run will take it.
     */
    private static function countdownLabel(Carbon $purgeAt): string
    {
        $days = (int) ceil(now()->floatDiffInDays($purgeAt, false));

        return match (true) {
            $days <= 0 => 'Permanently deleted soon',
            $days === 1 => 'Permanently deleted in 1 day',
            default => "Permanently deleted in {$days} days",
        };
    }
}
