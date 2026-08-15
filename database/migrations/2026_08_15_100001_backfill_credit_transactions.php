<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the ledger from history that predates it, so a user's balance can
 * always be explained by the rows behind it.
 *
 * Grants come from completed payments, spends from existing events. Rows are
 * replayed in chronological order and `balance_after` is computed as it goes,
 * so the running total is truthful even though the historic order of a payment
 * and an event creation on the same day is only as good as their timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('credit_transactions')->exists()) {
            return;
        }

        $movements = collect();

        foreach (DB::table('payments')->where('status', 'completed')->get() as $payment) {
            $movements->push([
                'user_id' => (int) $payment->user_id,
                'delta' => (int) $payment->credits_granted,
                'reason' => 'purchase',
                'payment_id' => (int) $payment->id,
                'event_id' => null,
                'at' => $payment->completed_at ?? $payment->created_at,
            ]);
        }

        foreach (DB::table('events')->get() as $event) {
            $movements->push([
                'user_id' => (int) $event->user_id,
                'delta' => -1,
                'reason' => 'event_created',
                'payment_id' => null,
                'event_id' => (int) $event->id,
                'at' => $event->created_at,
            ]);
        }

        $balances = [];
        $rows = [];

        foreach ($movements->sortBy('at') as $movement) {
            $userId = $movement['user_id'];
            $balances[$userId] = ($balances[$userId] ?? 0) + $movement['delta'];

            $rows[] = [
                'user_id' => $userId,
                'delta' => $movement['delta'],
                'reason' => $movement['reason'],
                'payment_id' => $movement['payment_id'],
                'event_id' => $movement['event_id'],
                // A historic sequence can dip below zero where credits were
                // granted by hand and never recorded; the ledger cannot show a
                // negative balance, so clamp it.
                'balance_after' => max(0, $balances[$userId]),
                'note' => 'Backfilled from history',
                'created_at' => $movement['at'],
                'updated_at' => $movement['at'],
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('credit_transactions')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('credit_transactions')->where('note', 'Backfilled from history')->delete();
    }
};
