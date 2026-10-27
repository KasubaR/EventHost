<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;

/**
 * Sends every bounce report to the bounce mailbox (plans/email-gaps.md Phase 3). Symfony Mailer uses the Return-Path as the
 * envelope sender, which is where a receiving server sends its report. Off while MAIL_BOUNCE_ADDRESS is unset.
 */
class SetBounceReturnPath
{
    public function handle(MessageSending $event): void
    {
        $address = config('mail.bounce_address');

        if (! is_string($address) || trim($address) === '' || ! $event->message instanceof Email) {
            return;
        }

        if ($event->message->getReturnPath() === null) {
            $event->message->returnPath(trim($address));
        }
    }
}
