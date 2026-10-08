<?php

namespace App\Support;

final class WhatsAppInviteLink
{
    /**
     * Build a WhatsApp chat deeplink. wa.me needs the country code, so a local Zambian number is dialled as +260 and
     * anything that is not one dialable number (too short, two numbers run together) returns null.
     */
    public static function url(?string $phone, string $message): ?string
    {
        $digits = GuestPhone::whatsAppDigits($phone);

        if ($digits === null) {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    public static function invitationMessage(string $guestName, string $eventName, string $rsvpUrl): string
    {
        return sprintf(
            "You are invited to %s!\n\nHi %s, RSVP here:\n%s",
            $eventName,
            $guestName,
            $rsvpUrl
        );
    }

    /**
     * Ticket purchase confirmation, sent the same way as invitationMessage()
     * — a wa.me deeplink the buyer (or the page) opens, not a server-initiated
     * send. Takes primitives rather than a Ticket model, same reasoning as
     * invitationMessage() above.
     */
    public static function ticketConfirmationMessage(
        string $eventName,
        string $attendeeName,
        string $ticketTypeName,
        string $eventDateLabel,
        ?string $venue,
        string $ticketUrl,
    ): string {
        $lines = [
            'Your ticket for '.$eventName.' is confirmed.',
            '',
            'Ticket: '.$ticketTypeName,
            'Name: '.$attendeeName,
            'Date: '.$eventDateLabel,
        ];

        if ($venue !== null && $venue !== '') {
            $lines[] = 'Venue: '.$venue;
        }

        $lines[] = '';
        $lines[] = 'View your ticket:';
        $lines[] = $ticketUrl;

        return implode("\n", $lines);
    }
}
