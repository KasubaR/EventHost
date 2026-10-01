<?php

namespace App\Enums;

enum EventStaffRole: string
{
    case Manager = 'manager';
    case CheckIn = 'checkin';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Event Manager',
            self::CheckIn => 'Check-in Staff',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Manager => 'Full ticketing access to ticket types, orders, and check-in. Cannot activate sales, delete the event, or manage staff.',
            self::CheckIn => 'Door access only. Scan and confirm tickets at check-in.',
        };
    }
}
