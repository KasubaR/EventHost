<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PublicRegistrationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public Event $event,
        public string $note,
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
            ->subject('Your event was not approved: '.$this->event->name)
            ->greeting('Hello!')
            ->line('EventHost was unable to approve "'.$this->event->name.'" for registration.')
            ->line('Reason: '.$this->note)
            ->line('You can make changes and resubmit for review at any time.')
            ->action(
                'Review and resubmit',
                route('events.edit', $this->event, absolute: true)
            )
            ->salutation('The '.config('app.name').' Team');
    }
}
