<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\TicketPayout;
use App\Support\ShortText;
use App\Support\TicketingSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Host-facing: sent when an admin records a payout (full or part) for a ticketed event. Gated by the
 * host's `email_payout_updates` preference, see CommunicationService::notifyHostPayoutRecorded().
 * Carries no attachment: a proof of payment, if the admin added one, is viewed on the Payouts page.
 */
class TicketPayoutRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public TicketPayout $payout,
        public float $remaining,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inFull = round($this->remaining, 2) <= 0;
        $amount = TicketingSettings::formatZmw($this->payout->amount);

        $mail = (new MailMessage)
            ->subject(($inFull ? 'Payout sent: ' : 'Part payout sent: ').ShortText::subject($this->event->name))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line('We have sent '.$amount.' from your ticket sales for '.$this->event->name.' on '.$this->payout->paid_on->format('j M Y').'.');

        if ($inFull) {
            $mail->line('This settles everything we owed you for this event so far.');
        } else {
            $mail->line('This is a part payment. '.TicketingSettings::formatZmw($this->remaining).' is still to be paid.');
        }

        if ($this->payout->note) {
            $mail->line('Note: '.$this->payout->note);
        }

        return $mail
            ->line('Your bank or mobile money provider may need extra time before the money reaches you.')
            ->action('View your payouts', route('public-events.tickets.payouts', ['event' => $this->event], absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
