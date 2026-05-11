<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $userName,
        public string $newEmail,
    ) {
        $this->onQueue('high');
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
            ->subject('Your '.config('app.name').' email address was changed')
            ->greeting('Hello, '.$this->userName)
            ->line('The email address on your account was updated to '.$this->newEmail.'.')
            ->line('If you did not make this change, please contact support immediately.')
            ->salutation('The '.config('app.name').' Team');
    }
}
