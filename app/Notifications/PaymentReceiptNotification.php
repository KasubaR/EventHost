<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\BillingPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Payment $payment)
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
            ->subject('Payment received: '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line('Your payment has been confirmed.')
            ->line('Plan: '.BillingPlan::labelForPlanKey($this->payment->plan_key))
            ->line('Amount: '.$this->payment->currency.' '.number_format((float) $this->payment->amount, 2))
            ->line('Reference: '.$this->payment->payment_reference)
            ->line('You now have '.$notifiable->fresh()->event_credits.' event credit(s).')
            ->action('Create an event', route('events.create'))
            ->salutation('The '.config('app.name').' Team');
    }
}
