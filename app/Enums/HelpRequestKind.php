<?php

namespace App\Enums;

/**
 * What a client is asking our team to do — plans/admin-create-events.md Step 0.
 */
enum HelpRequestKind: string
{
    case CreateEvent = 'create_event';
    case EditEvent = 'edit_event';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CreateEvent => 'Create an event for me',
            self::EditEvent => 'Help with an existing event',
            self::Other => 'Something else',
        };
    }
}
