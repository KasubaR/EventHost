<?php

namespace App\Notifications;

use App\Models\AdminHelpRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HelpRequestDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public AdminHelpRequest $helpRequest,
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
            ->subject('About your request for help')
            ->greeting('Hello!')
            ->line('We are unable to take on your request for help.');

        if (filled($this->helpRequest->decline_note)) {
            $mail->line('Reason: '.$this->helpRequest->decline_note);
        }

        return $mail
            ->line('Nothing on your account was changed. You are welcome to send a new request, or contact support.')
            ->action('Contact support', route('contact', absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
