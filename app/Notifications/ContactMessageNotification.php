<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly string $senderName,
        private readonly string $senderEmail,
        private readonly string $subject,
        private readonly string $body,
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
        $message = (new MailMessage)
            ->subject('['.$this->subject.'] Contact form message from '.$this->senderName)
            ->replyTo($this->senderEmail, $this->senderName)
            ->greeting('New contact form submission')
            ->line('**From:** '.$this->senderName.' <'.$this->senderEmail.'>')
            ->line('**Topic:** '.$this->subject)
            ->line('---');

        // Preserve the sender's paragraph breaks — MailMessage renders one line per call.
        foreach (preg_split('/\R{2,}/', trim($this->body)) ?: [] as $paragraph) {
            $message->line(trim($paragraph));
        }

        return $message
            ->line('---')
            ->line('Reply directly to this email to respond to '.$this->senderName.'.')
            ->salutation('The '.config('app.name').' Team');
    }
}
