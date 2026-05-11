<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct()
    {
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
            ->subject('Welcome to '.config('app.name').'!')
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line('Your account has been created successfully. We\'re excited to have you on board.')
            ->line('You\'ll receive a separate email shortly with a link to verify your email address. Please check your inbox and click that link to activate your account.')
            ->action('Go to '.config('app.name'), url('/'))
            ->line('Once verified, you can start creating beautiful digital invitations and managing your events.')
            ->salutation('The '.config('app.name').' Team');
    }
}
