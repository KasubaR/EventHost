<?php

namespace App\Notifications;

use App\Models\TicketOrder;
use App\Services\TicketPdfService;
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

        $eventLine = $order->event->event_date->format('l, F j, Y');
        if ($order->event->event_time) {
            $eventLine .= ' at '.Str::substr($order->event->event_time, 0, 5);
        }
        if ($order->event->venue) {
            $eventLine .= ', '.$order->event->venue;
        }

        $mail = (new MailMessage)
            ->subject('Your tickets for '.$order->event->name)
            ->greeting('Hi '.$order->buyer_name.',')
            ->line('Your payment was successful. Here are your tickets for '.$order->event->name.'.')
            ->line('Order reference: '.$order->order_reference)
            ->line($eventLine);

        foreach ($order->tickets as $ticket) {
            $mail->line($ticket->ticketType?->name.': '.$order->currency.' '.number_format((float) $ticket->price_paid, 2));
            $mail->action('View ticket', $ticket->publicUrl());
        }

        $mail->line('Your ticket PDFs are attached. Each includes the QR code to scan at the door.')
            ->salutation('The '.config('app.name').' Team');

        $ticketPdfService = app(TicketPdfService::class);
        foreach ($order->tickets as $ticket) {
            $mail->attachData(
                $ticketPdfService->render($ticket),
                'ticket-'.$order->order_reference.'-'.$ticket->id.'.pdf',
                ['mime' => 'application/pdf']
            );
        }

        return $mail;
    }
}
