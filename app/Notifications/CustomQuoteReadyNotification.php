<?php

namespace App\Notifications;

use App\Models\CustomQuote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomQuoteReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public CustomQuote $quote,
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
            ->subject('Your custom Enterprise quote is ready — '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line('EventHost has prepared a custom Enterprise quote for your account.')
            ->line('Amount due: '.$this->quote->formattedAmount().'.')
            ->line('Event credits included: '.$this->quote->credits_granted.'.');

        if (filled($this->quote->note)) {
            $mail->line('Details: '.$this->quote->note);
        }

        $mail->line('Pay securely from your billing page when you are ready.')
            ->action('Pay on Billing', route('billing.show', absolute: true))
            ->salutation('The '.config('app.name').' Team');

        return $mail;
    }
}
