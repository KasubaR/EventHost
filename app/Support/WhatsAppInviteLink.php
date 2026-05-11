<?php

namespace App\Support;

final class WhatsAppInviteLink
{
    /**
     * Build a WhatsApp chat deeplink. Returns null if the phone has no digits.
     */
    public static function url(?string $phone, string $message): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    public static function invitationMessage(string $guestName, string $eventName, string $rsvpUrl): string
    {
        return sprintf(
            "You are invited to %s!\n\nHi %s — RSVP here:\n%s",
            $eventName,
            $guestName,
            $rsvpUrl
        );
    }
}
