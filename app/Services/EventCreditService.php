<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes `users.event_credits`. Every movement takes a row
 * lock on the user and writes a matching credit_transactions row, so the balance
 * and its history can never drift apart.
 *
 * Both methods are safe to call from inside an existing transaction — Laravel
 * nests via savepoints.
 */
class EventCreditService
{
    public function grant(
        User $user,
        int $amount,
        string $reason,
        ?Payment $payment = null,
        ?string $note = null
    ): CreditTransaction {
        if ($amount < 1) {
            throw new \InvalidArgumentException('A credit grant must be at least 1.');
        }

        return DB::transaction(function () use ($user, $amount, $reason, $payment, $note): CreditTransaction {
            $locked = $this->lock($user);

            $locked->increment('event_credits', $amount);

            return $this->record($locked, $amount, $reason, $payment, null, $note);
        });
    }

    /**
     * @throws InsufficientCreditsException when the locked balance is empty
     */
    public function spend(
        User $user,
        string $reason,
        ?Event $event = null,
        ?string $note = null
    ): CreditTransaction {
        return DB::transaction(function () use ($user, $reason, $event, $note): CreditTransaction {
            $locked = $this->lock($user);

            if ($locked->event_credits < 1) {
                throw new InsufficientCreditsException;
            }

            $locked->decrement('event_credits');

            return $this->record($locked, -1, $reason, null, $event, $note);
        });
    }

    private function lock(User $user): User
    {
        /** @var User $locked */
        $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function record(
        User $locked,
        int $delta,
        string $reason,
        ?Payment $payment,
        ?Event $event,
        ?string $note
    ): CreditTransaction {
        return CreditTransaction::query()->create([
            'user_id' => $locked->id,
            'delta' => $delta,
            'reason' => $reason,
            'payment_id' => $payment?->id,
            'event_id' => $event?->id,
            // increment()/decrement() already refreshed the in-memory attribute.
            'balance_after' => (int) $locked->event_credits,
            'note' => $note,
        ]);
    }
}
