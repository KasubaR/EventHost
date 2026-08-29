<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Exceptions\TicketCheckInException;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Parallel to CheckInService (guests), not a shared abstraction — a ticket's
 * check-in gate is its own status column, not a nullable timestamp on a
 * separate "RSVP" record. See plans/ticketing.md §5.9.
 */
class TicketCheckInService
{
    /**
     * @return array{
     *     ticket: array{
     *         id: int, attendee_name: ?string, attendee_email: ?string,
     *         attendee_phone: ?string, ticket_type: ?string,
     *         order_reference: ?string, checked_in_at: ?string,
     *         checked_in_by: ?string,
     *     },
     *     already_checked_in: bool,
     * }
     *
     * $viaLabel identifies a no-login staff-link door (EventStaffLink::scanLabel()).
     * It is the only attribution those scans have — $staffUserId is null for
     * them, since there is no account behind the link.
     */
    public function confirm(Ticket $ticket, ?int $staffUserId, ?string $viaLabel = null): array
    {
        return DB::transaction(function () use ($ticket, $staffUserId, $viaLabel): array {
            /** @var Ticket $locked */
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['event', 'ticketType', 'order', 'checkedInBy']);

            if ($locked->event === null || ! $locked->event->isCheckInOpen()) {
                throw new TicketCheckInException($locked->event?->checkInClosedReason()
                    ?? 'Check-in is not open for this event yet, or has already closed.');
            }

            if ($locked->status === TicketStatus::Cancelled || $locked->status === TicketStatus::Refunded) {
                throw new TicketCheckInException('This ticket has been cancelled.');
            }

            // Not an error — a re-scan at the door is a normal occurrence (staff
            // double-checking, a guest re-presenting the same QR). The ticket
            // simply isn't marked in again; the response tells the scanner it was
            // already used so staff can decide whether that's expected.
            $alreadyIn = $locked->status === TicketStatus::Used;

            if (! $alreadyIn) {
                $locked->forceFill([
                    'status' => TicketStatus::Used,
                    'checked_in_at' => now(),
                    'checked_in_by' => $staffUserId,
                    'checked_in_via_label' => $viaLabel,
                ])->save();
            }

            return [
                'ticket' => [
                    'id' => $locked->id,
                    'attendee_name' => $locked->attendee_name,
                    'attendee_email' => $locked->attendee_email,
                    'attendee_phone' => $locked->attendee_phone,
                    'ticket_type' => $locked->ticketType?->name,
                    'order_reference' => $locked->order?->order_reference,
                    'checked_in_at' => $locked->checked_in_at?->toIso8601String(),
                    // Which door took it the first time — the scanner shows this
                    // on a repeat so staff can tell a re-entry at their own gate
                    // from a copy surfacing at a different one.
                    'checked_in_by' => $locked->checkedInByLabel(),
                ],
                'already_checked_in' => $alreadyIn,
            ];
        });
    }
}
