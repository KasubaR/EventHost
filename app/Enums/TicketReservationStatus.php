<?php

namespace App\Enums;

enum TicketReservationStatus: string
{
    case Held = 'held';
    case Converted = 'converted';
    case Expired = 'expired';
    case Released = 'released';
}
