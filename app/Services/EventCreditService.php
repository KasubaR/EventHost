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

        $this->assertReason($reason);

        return DB::transaction(function () use ($user, $amount, $reason, $payment, $note): CreditTransaction {
            $locked = $this->lock($user);

            User::query()->whereKey($locked->id)->increment('event_credits', $amount);
            $locked->event_credits = (int) $locked->event_credits + $amount;
            $locked->syncOriginalAttribute('event_credits');

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
        $this->assertReason($reason);

        return DB::transaction(function () use ($user, $reason, $event, $note): CreditTransaction {
            $locked = $this->lock($user);

            if ($locked->event_credits < 1) {
                throw new InsufficientCreditsException;
            }

            User::query()->whereKey($locked->id)->decrement('event_credits');
            $locked->event_credits = (int) $locked->event_credits - 1;
            $locked->syncOriginalAttribute('event_credits');

            return $this->record($locked, -1, $reason, null, $event, $note);
        });
    }

    /**
     * First publish spends one credit. A draft that already consumed a credit
     * at creation (the old rule) publishes without a second spend. Already
     * published events are a no-op.
     *
     * Must run inside a transaction with the event row locked.
     */
    public function chargeFirstPublish(User $user, Event $event): void
    {
        if ($event->is_published || $event->hasConsumedPublishCredit()) {
            return;
        }

        $this->spend($user, CreditTransaction::REASON_EVENT_PUBLISHED, $event);
    }

    /**
     * Claw back unused credits from a reversed purchase. Takes at most the
     * remaining balance — a credit already spent on a live event cannot go
     * negative. Always writes a refund row so the attempt is auditable.
     */
    public function reversePurchase(User $user, Payment $payment, ?string $note = null): CreditTransaction
    {
        $this->assertReason(CreditTransaction::REASON_REFUND);

        return DB::transaction(function () use ($user, $payment, $note): CreditTransaction {
            $locked = $this->lock($user);
            $requested = max(0, (int) $payment->credits_granted);
            $take = min($requested, (int) $locked->event_credits);

            if ($take > 0) {
                User::query()->whereKey($locked->id)->decrement('event_credits', $take);
                $locked->event_credits = (int) $locked->event_credits - $take;
                $locked->syncOriginalAttribute('event_credits');
            }

            $suffix = $take < $requested
                ? 'Reversed '.$take.' of '.$requested.'; the rest had already been spent.'
                : null;

            return $this->record(
                $locked,
                -$take,
                CreditTransaction::REASON_REFUND,
                $payment,
                null,
                trim(implode(' ', array_filter([$note, $suffix])))
            );
        });
    }

    private function assertReason(string $reason): void
    {
        if (! array_key_exists($reason, CreditTransaction::REASONS)) {
            throw new \InvalidArgumentException('Unknown credit reason ['.$reason.'].');
        }
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
        $row = new CreditTransaction;
        $row->forceFill([
            'user_id' => $locked->id,
            'delta' => $delta,
            'reason' => $reason,
            'payment_id' => $payment?->id,
            'event_id' => $event?->id,
            'balance_after' => (int) $locked->event_credits,
            'note' => $note !== null && $note !== '' ? $note : null,
        ]);
        $row->save();

        return $row;
    }
}
