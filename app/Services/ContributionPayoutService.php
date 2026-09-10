<?php

namespace App\Services;

use App\Exceptions\ContributionPayoutExceedsBalanceException;
use App\Models\Admin;
use App\Models\ContributionPayout;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place that should write to contribution_payouts. Manual,
 * admin-recorded — there is no Lenco disbursement call here, same posture as
 * TicketPayoutService. Parallel to ContributionRevenueAnalyticsService
 * rather than folded into it, same reasoning TicketPayoutService stayed
 * parallel to TicketRevenueLedgerService.
 */
class ContributionPayoutService
{
    public function __construct(
        private readonly ContributionRevenueAnalyticsService $analytics,
    ) {}

    /**
     * @throws ContributionPayoutExceedsBalanceException when $amount is <= 0
     *                                                   or exceeds the event's current pending balance
     */
    public function recordPayout(
        Event $event,
        Admin $admin,
        float $amount,
        ?string $note,
        Carbon $paidOn,
    ): ContributionPayout {
        return DB::transaction(function () use ($event, $admin, $amount, $note, $paidOn): ContributionPayout {
            $locked = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            $balance = $this->analytics->balanceFor($locked);

            if ($amount <= 0 || $amount > $balance) {
                throw new ContributionPayoutExceedsBalanceException;
            }

            $payout = new ContributionPayout;
            $payout->forceFill([
                'event_id' => $locked->id,
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
