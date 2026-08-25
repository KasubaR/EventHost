<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EventUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public string $updateMessage,
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
            ->subject('Update: '.$this->event->name)
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('There is an update for '.$this->event->name.'.')
            ->line($this->updateMessage);

        if ($this->guest->invitation_token) {
            $mail->action(
                'View invitation',
                route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            );
        } elseif ($this->event->is_public) {
            $mail->action(
                'View invitation',
                route('events.public', ['slug' => $this->event->slug], absolute: true)
            );
        }

        return $mail->salutation('The '.config('app.name').' Team');
    }
}
