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
