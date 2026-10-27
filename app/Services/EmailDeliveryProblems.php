<?php

namespace App\Services;

use App\Models\EmailSuppression;
use App\Models\Guest;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\DB;

/**
 * Which guests' email is not getting through, for the guest list and GuestResource::email_delivery (plans/email-gaps.md
 * Phase 3). Two sources: the address hard-bounced (suppressed for every event until the host says "Try this address again"),
 * or the latest email to this guest could not be handed to the mail server. Loaded once per event per request.
 */
class EmailDeliveryProblems
{
    /** @var array<int, array<int, array{kind: string, reason: string, suppressed: bool}>> */
    private array $byEvent = [];

    /**
     * @return array{kind: 'bounced'|'failed', reason: string, suppressed: bool}|null
     */
    public function for(Guest $guest): ?array
    {
        if (blank($guest->email)) {
            return null;
        }

        $this->byEvent[$guest->event_id] ??= $this->load((int) $guest->event_id);

        return $this->byEvent[$guest->event_id][$guest->id] ?? null;
    }

    public function forget(int $eventId): void
    {
        unset($this->byEvent[$eventId]);
    }

    /**
     * @return array<int, array{kind: string, reason: string, suppressed: bool}>
     */
    private function load(int $eventId): array
    {
        $problems = [];

        $suppressions = EmailSuppression::query()
            ->whereIn('email', Guest::query()->where('event_id', $eventId)->whereNotNull('email')->select(DB::raw('LOWER(email)')))
            ->get()
            ->keyBy('email');

        if ($suppressions->isNotEmpty()) {
            Guest::query()
                ->where('event_id', $eventId)
                ->whereIn(DB::raw('LOWER(email)'), $suppressions->keys()->all())
                ->get(['id', 'email'])
                ->each(function (Guest $guest) use (&$problems, $suppressions): void {
                    $suppression = $suppressions[mb_strtolower(trim((string) $guest->email))] ?? null;

                    if ($suppression !== null) {
                        $problems[$guest->id] = ['kind' => 'bounced', 'reason' => $suppression->reasonLabel(), 'suppressed' => true];
                    }
                });
        }

        // The latest outcome per guest; a later email that went through clears an earlier failure.
        $latest = NotificationLog::query()
            ->selectRaw('MAX(id)')
            ->where('event_id', $eventId)
            ->where('channel', 'email')
            ->whereNotNull('guest_id')
            ->whereNotIn('type', NotificationLog::HOST_EMAIL_TYPES)
            ->whereIn('delivery_status', [NotificationLog::DELIVERY_ACCEPTED, NotificationLog::DELIVERY_FAILED, NotificationLog::DELIVERY_BOUNCED])
            ->groupBy('guest_id');

        NotificationLog::query()
            ->whereIn('id', $latest)
            ->where('delivery_status', NotificationLog::DELIVERY_FAILED)
            ->get()
            ->each(function (NotificationLog $log) use (&$problems): void {
                // Went to an address the guest no longer has (GuestUpdater marks those rows).
                if ((is_array($log->meta) ? ($log->meta['superseded'] ?? null) : null) === 'contact_change') {
                    return;
                }

                $problems[$log->guest_id] ??= ['kind' => 'failed', 'reason' => $log->undeliveredReason(), 'suppressed' => false];
            });

        return $problems;
    }
}
