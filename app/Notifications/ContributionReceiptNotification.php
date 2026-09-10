<?php

namespace App\Notifications;

use App\Models\ContributionPayment;
use App\Models\EventContribution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent on-demand (contributors have no user account) once a
 * ContributionPayment settles — one per completed installment, not just the
 * final one, so a contributor paying in parts gets a receipt each time.
 * Mirrors TicketOrderConfirmationNotification's shape. See
 * plans/contributions.md.
 */
class ContributionReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public EventContribution $contribution,
        public ContributionPayment $payment,
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
        $contribution = $this->contribution->loadMissing('event');
        $remaining = $contribution->remainingAmount();

        $mail = (new MailMessage)
            ->subject('Thank you for your contribution to '.$contribution->event->name)
            ->greeting('Hi '.$contribution->contributor_name.',')
            ->line('We received your payment of '.$contribution->currency.' '.number_format((float) $this->payment->amount, 2).' toward '.$contribution->event->name.'.')
            ->line('Total paid so far: '.$contribution->currency.' '.number_format((float) $contribution->amount_paid, 2).' of '.number_format((float) $contribution->target_amount, 2).'.');

        if ($contribution->isCompleted()) {
            $mail->line('Your contribution is now paid in full — thank you!');
        } else {
            $mail->line('Remaining balance: '.$contribution->currency.' '.number_format($remaining, 2).'.')
                ->action('Pay the rest', route('contributions.show', $contribution->reference, absolute: true));
        }

        return $mail->salutation('The '.config('app.name').' Team');
    }
}
