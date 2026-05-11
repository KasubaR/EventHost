<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Guest;
use App\Support\WhatsAppInviteLink;

class WhatsAppService
{
    public function invitationShareUrl(Event $event, Guest $guest): ?string
    {
        $rsvpUrl = $guest->personalRsvpUrl();
        if ($rsvpUrl === null) {
            return null;
        }

        $message = WhatsAppInviteLink::invitationMessage($guest->name, $event->name, $rsvpUrl);

        return WhatsAppInviteLink::url($guest->phone, $message);
    }
}
