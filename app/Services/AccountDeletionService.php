<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a host account for both the web and the API controller, so the two cannot
 * drift (plans/event-retention.md §6b).
 *
 * `events.user_id` cascades, so deleting the user destroys every event under it — and
 * with a ticket order or contribution payment on file, that destroys a payment record
 * the Privacy policy says is kept. Two things are refused for that reason:
 *
 * - any event, including one in Recently deleted, that Event::hasRetainedFinancialRecords()
 *   says has taken money — the same definition the purge job uses;
 * - a payment of the user's own that can still settle.
 *
 * The user's own payments (`payments`) are kept: the ones that moved money stay, with a
 * name and email snapshot because their user_id is about to be nulled by the FK; the ones
 * that never did (failed, cancelled, never-finished checkouts) are deleted with the account.
 */
class AccountDeletionService
{
    public const BLOCKED_BY_EVENTS = 'events';

    public const BLOCKED_BY_PAYMENT = 'payment';

    /** Statuses whose rows are kept: money moved, or may still be mid-flight at the provider. */
    private const KEPT_PAYMENT_STATUSES = ['completed', 'refunded', 'processing'];

    /**
     * Why this account cannot be deleted right now, or null when it can.
     *
     * @return self::BLOCKED_BY_*|null
     */
    public function blocker(User $user): ?string
    {
        if ($user->events()->withTrashed()->withRetainedFinancialRecords()->exists()) {
            return self::BLOCKED_BY_EVENTS;
        }

        if ($user->payments()->stillResolving()->exists()) {
            return self::BLOCKED_BY_PAYMENT;
        }

        return null;
    }

    /** What the user is told; shared so the web and API responses say the same thing. */
    public static function messageFor(string $blocker): string
    {
        return match ($blocker) {
            self::BLOCKED_BY_EVENTS => 'One of your events (including any in Recently deleted) has ticket sales, refunds or '
                .'contribution payments on record, which we have to keep. Contact support to wind them down — and settle '
                .'any pending payout — before deleting your account.',
            default => 'A payment on your account is still being processed. Wait for it to finish — it can take up to '
                .Payment::IN_FLIGHT_HOURS.' hours — then try again.',
        };
    }

    /**
     * Deletes the account unless something blocks it, and returns the blocker (null on success).
     * The check and the delete share one transaction that holds the user's row lock —
     * PaymentController::initiate() takes the same lock, so a checkout cannot start between
     * the guard passing and the cascade running.
     *
     * @return self::BLOCKED_BY_*|null
     */
    public function delete(User $user): ?string
    {
        return DB::transaction(function () use ($user): ?string {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $blocker = $this->blocker($locked);

            if ($blocker !== null) {
                return $blocker;
            }

            $this->settlePayments($locked);

            $locked->delete();

            return null;
        });
    }

    /**
     * Drop the payments that never moved money, and label the rest with who paid — once the
     * user row goes, that link is all an accountant would have had.
     */
    private function settlePayments(User $user): void
    {
        $user->payments()->whereNotIn('status', self::KEPT_PAYMENT_STATUSES)->delete();

        Payment::query()
            ->where('user_id', $user->getKey())
            ->whereNull('payer_email')
            ->update([
                'payer_name' => $user->name,
                'payer_email' => $user->email,
            ]);
    }
}
