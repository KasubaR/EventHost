<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketingApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public Event $event,
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
        $mail = (new MailMessage)
            ->subject('Ticket sales are live: '.$this->event->name)
            ->greeting('Good news!')
            ->line('EventHost has approved ticket sales for "'.$this->event->name.'".')
            ->line('Your public ticket page is now live.');

        if ($this->event->agreed_payout_on) {
            $mail->line('Agreed payout date: '.$this->event->agreed_payout_on->format('j M Y').'.');
        }

        $mail->action(
            'View ticket page',
            route('events.public', ['slug' => $this->event->slug], absolute: true)
        );

        return $mail->salutation(config('app.name'));
    }
}
