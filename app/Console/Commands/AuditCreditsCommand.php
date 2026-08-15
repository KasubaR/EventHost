<?php

namespace App\Console\Commands;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Console\Command;

class AuditCreditsCommand extends Command
{
    protected $signature = 'credits:audit';

    protected $description = 'Report users whose event_credits balance does not match the ledger sum';

    public function handle(): int
    {
        $mismatched = 0;

        User::query()->orderBy('id')->chunkById(100, function ($users) use (&$mismatched): void {
            foreach ($users as $user) {
                $sum = (int) CreditTransaction::query()->where('user_id', $user->id)->sum('delta');
                $balance = (int) $user->event_credits;

                if ($sum !== $balance) {
                    $this->error("user {$user->id} ({$user->email}): balance {$balance}, ledger {$sum}");
                    $mismatched++;
                }
            }
        });

        if ($mismatched > 0) {
            $this->error("{$mismatched} user(s) have a credit balance that does not match the ledger.");

            return self::FAILURE;
        }

        $this->info('All credit balances match the ledger.');

        return self::SUCCESS;
    }
}
