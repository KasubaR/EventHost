<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnterpriseQuoteRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public User $user,
        public ?string $message,
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
        $mail = (new MailMessage)
            ->subject('Enterprise quote requested: '.$this->user->name)
            ->replyTo($this->user->email, $this->user->name)
            ->greeting('New Enterprise quote request')
            ->line('**From:** '.$this->user->name.' <'.$this->user->email.'>');

        if (filled($this->user->phone)) {
            $mail->line('**Phone:** '.$this->user->phone);
        }

        if (filled($this->user->company_name)) {
            $mail->line('**Company:** '.$this->user->company_name);
        }

        if (filled($this->message)) {
            $mail->line('**Message**')
                ->line($this->message);
        }

        return $mail
            ->action('View in admin panel', route('admin.users.show', $this->user, absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
