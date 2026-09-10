<?php

namespace App\Notifications;

use App\Models\ContributionPayment;
use App\Models\EventContribution;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Host-facing — sent to the event owner on every completed installment, same
 * trigger point as ContributionReceiptNotification. Mirrors
 * NewRsvpReceivedNotification's shape. Gated by the host's
 * `email_contribution_updates` preference — see
 * CommunicationService::notifyHostNewContribution().
 */
class NewContributionReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

        $mail = (new MailMessage)
            ->subject('New contribution from '.$contribution->contributor_name.': '.$contribution->event->name)
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($contribution->contributor_name.' just paid '.$contribution->currency.' '.number_format((float) $this->payment->amount, 2).' toward '.$contribution->event->name.'.')
            ->line('Total from them so far: '.$contribution->currency.' '.number_format((float) $contribution->amount_paid, 2).' of '.number_format((float) $contribution->target_amount, 2).'.');

        if ($contribution->isCompleted()) {
            $mail->line('Their contribution is now paid in full.');
        }

        return $mail
            ->action('View revenue', route('events.show', ['event' => $contribution->event], absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
