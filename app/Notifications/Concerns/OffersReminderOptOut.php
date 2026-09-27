<?php

namespace App\Notifications\Concerns;

use App\Models\Guest;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * The "stop these emails" footer for the guest reminder emails (plans/guest-email-reminders.md Phase 3):
 * a visible link, plus the List-Unsubscribe headers mail clients turn into their own "Unsubscribe" button.
 * The button POSTs to the same signed URL as the link (RFC 8058 one-click), which is why the endpoint is
 * exempt from CSRF and never trusts anything but the signature.
 */
trait OffersReminderOptOut
{
    protected function withReminderOptOut(MailMessage $mail, Guest $guest, string $eventName): MailMessage
    {
        $url = $guest->stopEmailRemindersUrl();

        return $mail
            ->line('Do not want these reminders for '.$eventName.'? [Stop reminder emails]('.$url.').')
            ->withSymfonyMessage(function (Email $message) use ($url): void {
                $headers = $message->getHeaders();
                $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}
