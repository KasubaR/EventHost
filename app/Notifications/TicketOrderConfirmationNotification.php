<?php

namespace App\Notifications;

use App\Models\TicketOrder;
use App\Services\QrCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sent on-demand (buyers have no user account) once TicketOrderFulfillmentService
 * issues tickets. Mirrors PaymentReceiptNotification's shape.
 */
class TicketOrderConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public TicketOrder $order)
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
        $order = $this->order->loadMissing(['event', 'tickets.ticketType']);

        $mail = (new MailMessage)
            ->subject('Your tickets for '.$order->event->name)
            ->greeting('Hi '.$order->buyer_name.',')
            ->line('Your payment was successful — here are your tickets for '.$order->event->name.'.')
            ->line('Order reference: '.$order->order_reference);

        foreach ($order->tickets as $ticket) {
            $mail->line($ticket->ticketType?->name.' — '.$order->currency.' '.number_format((float) $ticket->price_paid, 2));
            $mail->action('View ticket', $ticket->publicUrl());
        }

        $mail->line('Keep this email — each ticket link shows the QR code to scan at the door.')
            ->salutation('The '.config('app.name').' Team');

        $qrService = app(QrCodeService::class);
        foreach ($order->tickets as $ticket) {
            $mail->attachData(
                $qrService->png($ticket->publicUrl()),
                'ticket-'.Str::slug((string) $ticket->id).'.png',
                ['mime' => 'image/png']
            );
        }

        return $mail;
    }
}
