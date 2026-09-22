<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PublicRegistrationApprovedNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Your event was approved: '.$this->event->name)
            ->greeting('Good news!')
            ->line('EventHost has approved "'.$this->event->name.'" for registration.')
            ->line('Quoted amount: ZMW '.number_format((float) $this->event->public_registration_quote_amount, 2))
            ->line('Pay this amount to make your event live at its public link.')
            ->action(
                'Pay and publish',
                route('events.public-registration.pay', $this->event, absolute: true)
            )
            ->salutation('The '.config('app.name').' Team');
    }
}
