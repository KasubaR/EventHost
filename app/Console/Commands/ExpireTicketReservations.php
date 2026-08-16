<?php

namespace App\Console\Commands;

use App\Enums\TicketReservationStatus;
use App\Models\TicketReservation;
use Illuminate\Console\Command;

/**
 * Flips lapsed holds to `expired` so their capacity frees up. Holds already
 * tied to an order stay Held until that order is paid or cancelled — otherwise
 * expiry would free the seat while Lenco is still collecting.
 * See plans/ticketing.md §5.5.
 */
class ExpireTicketReservations extends Command
{
    protected $signature = 'tickets:expire-reservations';

    protected $description = 'Expire ticket reservation holds past their window that are not tied to an order';

    public function handle(): int
    {
        $count = TicketReservation::query()
            ->where('status', TicketReservationStatus::Held)
            ->whereNull('ticket_order_id')
            ->where('expires_at', '<=', now())
            ->update(['status' => TicketReservationStatus::Expired]);

        if ($count > 0) {
            $this->info("Expired {$count} ticket reservation(s).");
        }

        return self::SUCCESS;
    }
}
