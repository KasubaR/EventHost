<?php

namespace App\Services;

use App\Exceptions\TicketPayoutExceedsBalanceException;
use App\Models\Admin;
use App\Models\Event;
use App\Models\TicketPayout;
use App\Models\TicketRevenueEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place that should write to ticket_payouts (and, alongside it, the
 * `payout` TicketRevenueEntry it produces). Manual, admin-recorded — there is
 * no Lenco disbursement call anywhere in here (plans/ticketing.md: "Manual.
 * Admin records a payout... No Lenco disbursement in V1"). Parallel to
 * TicketRevenueLedgerService rather than folded into it — this is an admin
 * action with its own authorization/validation shape, same reasoning
 * TicketPaymentStatusService stayed parallel to PaymentStatusService.
 */
class TicketPayoutService
{
    public function __construct(
        private readonly TicketRevenueLedgerService $ledger,
    ) {}

    /**
     * @throws TicketPayoutExceedsBalanceException when $amount is <= 0 or
     *                                             exceeds the event's current pending balance
     */
    public function recordPayout(
        Event $event,
        Admin $admin,
        float $amount,
        ?string $note,
        Carbon $paidOn,
    ): TicketPayout {
        return DB::transaction(function () use ($event, $admin, $amount, $note, $paidOn): TicketPayout {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $balance = $this->ledger->balanceFor($locked);

            if ($amount <= 0 || $amount > $balance) {
                throw new TicketPayoutExceedsBalanceException;
            }

            $entry = new TicketRevenueEntry;
            $entry->forceFill([
                'event_id' => $locked->id,
                'ticket_order_id' => null,
                'type' => TicketRevenueEntry::TYPE_PAYOUT,
                'gross_amount' => 0,
                'platform_fee' => 0,
                'buyer_fee' => 0,
                'host_amount' => -$amount,
                'buyer_total' => 0,
                'currency' => 'ZMW',
                'balance_after' => round($balance - $amount, 2),
                'note' => $note !== null && $note !== '' ? $note : null,
            ]);
            $entry->save();

            $payout = new TicketPayout;
            $payout->forceFill([
                'event_id' => $locked->id,
                'ticket_revenue_entry_id' => $entry->id,
                'amount' => $amount,
                'currency' => 'ZMW',
                'paid_on' => $paidOn->toDateString(),
                'note' => $note !== null && $note !== '' ? $note : null,
                'paid_by' => $admin->id,
            ]);
            $payout->save();

            return $payout;
        });
    }
}
