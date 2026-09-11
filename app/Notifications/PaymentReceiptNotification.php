<?php

namespace App\Notifications;

use App\Models\Event;
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
        $mail = (new MailMessage)
            ->subject('Payment received: '.config('app.name'))
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line('Your payment has been confirmed.')
            ->line('Plan: '.BillingPlan::labelForPlanKey($this->payment->plan_key))
            ->line('Amount: '.$this->payment->currency.' '.number_format((float) $this->payment->amount, 2))
            ->line('Reference: '.$this->payment->payment_reference);

        // remove_branding grants neither credits nor a tier — the normal
        // "you now have N credits" line would be misleading here.
        if ($this->payment->plan_key === 'remove_branding') {
            $eventId = data_get($this->payment->metadata, 'event_id');
            $event = is_numeric($eventId) ? Event::query()->find((int) $eventId) : null;

            return $mail
                ->line('The EventHost bar no longer shows on '.($event?->name ?? 'that event').'\'s public pages.')
                ->action('View event', $event !== null ? route('events.show', $event) : route('events.index'))
                ->salutation('The '.config('app.name').' Team');
        }

        return $mail
            ->line('You now have '.$notifiable->fresh()->event_credits.' event credit(s).')
            ->action('Create an event', route('events.create'))
            ->salutation('The '.config('app.name').' Team');
    }
}
