<?php

namespace App\Support;

use App\Models\Event;

/**
 * The one place that decides whether ticket type quantities fit inside an
 * event's total capacity. Used by the host and admin ticket type requests and
 * by the event update request, so the rule cannot drift between them.
 *
 * An event with no `ticket_capacity` (older events, events made through the
 * API) has no cap and nothing here applies.
 */
class TicketCapacity
{
    /**
     * Why a ticket type with this quantity cannot be saved, or null when it fits.
     */
    public static function typeProblem(Event $event, ?int $quantity, ?int $exceptTicketTypeId = null): ?string
    {
        $capacity = $event->ticket_capacity;

        if ($capacity === null) {
            return null;
        }

        if ($quantity === null) {
            return 'Enter how many tickets of this type are available. Your event capacity is '.number_format($capacity).'.';
        }

        $left = $capacity - $event->ticketCapacityAllocated($exceptTicketTypeId);

        if ($quantity <= $left) {
            return null;
        }

        return $left > 0
            ? 'Only '.number_format($left).' of your '.number_format($capacity).' event capacity '.($left === 1 ? 'is' : 'are').' left to give out. Enter '.number_format($left).' or fewer, or raise the event capacity first.'
            : 'All '.number_format($capacity).' tickets of your event capacity are already given to other ticket types. Lower another type or raise the event capacity first.';
    }

    /**
     * Why the event total cannot be set to this number, or null when it can.
     */
    public static function totalProblem(Event $event, int $capacity): ?string
    {
        if ($event->ticketTypes()->whereNull('quantity')->exists()) {
            return 'Give every ticket type a quantity before setting a total capacity.';
        }

        $allocated = $event->ticketCapacityAllocated();

        if ($capacity < $allocated) {
            return 'Your ticket types already add up to '.number_format($allocated).' tickets. Lower their quantities first, or enter '.number_format($allocated).' or more.';
        }

        return null;
    }
}
