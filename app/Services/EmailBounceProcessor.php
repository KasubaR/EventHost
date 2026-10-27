<?php

namespace App\Services;

use App\Models\EmailSuppression;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Support\BounceReport;
use Illuminate\Support\Facades\DB;

/**
 * What one bounce report changes (plans/email-gaps.md Phase 3). The report must match an email we sent: by the signed
 * X-EventHost-Ref it quotes back, else by the newest email to a guest with that address in the last week. An unmatched
 * report changes nothing, so a forged bounce cannot stop mail to an address we never wrote to.
 */
class EmailBounceProcessor
{
    public const MATCH_WINDOW_DAYS = 7;

    /** @return 'suppressed'|'soft'|'unmatched' */
    public function process(BounceReport $report): string
    {
        $log = $this->matchLog($report);
        if ($log === null) {
            return 'unmatched';
        }

        return DB::transaction(function () use ($report, $log): string {
            $meta = is_array($log->meta) ? $log->meta : [];
            $meta['bounce'] = array_filter([
                'status' => $report->status,
                'diagnostic' => $report->diagnostic,
            ]);

            if (! $report->isHard()) {
                $log->forceFill(['meta' => $meta])->save();

                return 'soft';
            }

            $log->forceFill([
                'delivery_status' => NotificationLog::DELIVERY_BOUNCED,
                'error_code' => $report->status,
                'meta' => $meta,
            ])->save();

            $suppression = EmailSuppression::query()->lockForUpdate()->where('email', $report->recipient)->first();

            if ($suppression === null) {
                EmailSuppression::query()->create([
                    'email' => $report->recipient,
                    'reason' => EmailSuppression::REASON_HARD_BOUNCE,
                    'status_code' => $report->status,
                    'diagnostic' => $report->diagnostic,
                    'notification_log_id' => $log->id,
                    'first_bounced_at' => now(),
                    'last_bounced_at' => now(),
                ]);
            } else {
                $suppression->forceFill([
                    'status_code' => $report->status,
                    'diagnostic' => $report->diagnostic,
                    'notification_log_id' => $log->id,
                    'bounce_count' => $suppression->bounce_count + 1,
                    'last_bounced_at' => now(),
                ])->save();
            }

            return 'suppressed';
        });
    }

    private function matchLog(BounceReport $report): ?NotificationLog
    {
        $id = NotificationLog::idFromMailRef($report->ref);
        if ($id !== null) {
            $log = NotificationLog::query()->where('channel', 'email')->find($id);

            // The reference is ours, but the report must also be about the guest it went to.
            if ($log !== null && ($log->guest_id === null || $this->guestEmailIs($log->guest_id, $report->recipient))) {
                return $log;
            }
        }

        $guestIds = Guest::query()
            ->whereRaw('LOWER(email) = ?', [$report->recipient])
            ->pluck('id');

        if ($guestIds->isEmpty()) {
            return null;
        }

        return NotificationLog::query()
            ->where('channel', 'email')
            ->whereIn('guest_id', $guestIds)
            ->whereNotIn('type', NotificationLog::HOST_EMAIL_TYPES)
            ->where('status', NotificationLog::STATUS_SENT)
            ->where('created_at', '>=', now()->subDays(self::MATCH_WINDOW_DAYS))
            ->latest('id')
            ->first();
    }

    private function guestEmailIs(int $guestId, string $email): bool
    {
        $current = Guest::query()->whereKey($guestId)->value('email');

        return is_string($current) && mb_strtolower(trim($current)) === $email;
    }
}
