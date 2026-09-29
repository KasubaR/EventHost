<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off: writes a ledger-only correction for users whose event_credits
 * balance predates App\Services\EventCreditService.
 *
 * Migration 2026_05_24_185558_add_event_credits_to_users_table backfilled
 * `users.event_credits` for every existing user with a raw
 * `UPDATE ... SET event_credits = (SELECT COUNT(*) FROM events ...)` — no
 * credit_transactions row was (or could have been) written for it, since the
 * table didn't exist for CreditTransaction rows to reason about yet. Every
 * balance change since has gone through EventCreditService and written a
 * matching row, so that one migration is the *only* source of drift
 * credits:audit can ever report; there is nothing else to find.
 *
 * This command finds each mismatched user, inserts one REASON_LEDGER_BACKFILL
 * row for exactly the missing amount, and does not touch `event_credits` —
 * the balance was already correct, only its history was incomplete. Safe to
 * re-run: once a user's ledger sum matches their balance, they are no longer
 * selected.
 */
class ReconcileLegacyCreditLedgerCommand extends Command
{
    protected $signature = 'credits:reconcile-legacy-backfill {--dry-run : List what would be written without writing it}';

    protected $description = 'Write a ledger-only correction for users whose event_credits balance predates the credit ledger';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        User::query()->orderBy('id')->chunkById(100, function ($users) use ($dryRun, &$fixed): void {
            foreach ($users as $user) {
                $sum = (int) CreditTransaction::query()->where('user_id', $user->id)->sum('delta');
                $balance = (int) $user->event_credits;
                $missing = $balance - $sum;

                if ($missing === 0) {
                    continue;
                }

                $this->info("user {$user->id} ({$user->email}): ledger {$sum} -> {$balance}, backfilling {$missing}");

                if ($dryRun) {
                    $fixed++;

                    continue;
                }

                DB::transaction(function () use ($user): void {
                    // Row-lock, then recompute both figures under the lock — a
                    // concurrent EventCreditService movement on this user could
                    // otherwise land between our sum() above and this insert.
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $sum = (int) CreditTransaction::query()->where('user_id', $locked->id)->sum('delta');
                    $balance = (int) $locked->event_credits;
                    $missing = $balance - $sum;

                    if ($missing === 0) {
                        return;
                    }

                    $row = new CreditTransaction;
                    $row->forceFill([
                        'user_id' => $locked->id,
                        'delta' => $missing,
                        'reason' => CreditTransaction::REASON_LEDGER_BACKFILL,
                        'balance_after' => $balance,
                        'note' => 'Backfills the pre-ledger grant from migration 2026_05_24_185558_add_event_credits_to_users_table.',
                    ]);
                    $row->save();
                });

                $fixed++;
            }
        });

        if ($fixed === 0) {
            $this->info('No mismatched balances found — nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Would reconcile ' : 'Reconciled ').$fixed.' user(s).');

        return self::SUCCESS;
    }
}
