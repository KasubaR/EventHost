<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Notifications\Concerns\GuardsGuestMail;
use App\Support\ShortText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A cancelled event still gets its update: the host may be using it to say so. A deleted one does not (GuardsGuestMail).
 */
class EventUpdatedNotification extends Notification implements ShouldQueue
{
    use GuardsGuestMail;
    use Queueable;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public string $updateMessage,
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
            ->subject('Update: '.ShortText::subject($this->event->name))
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('There is an update for '.$this->event->name.'.')
            ->line($this->updateMessage);

        if ($this->event->isCancelled()) {
            $mail->line('**This event has been cancelled.**');
        } else {
            // Read when the email is sent, so an edit made after the host wrote the message still shows here.
            $mail->line('**Date:** '.($this->event->event_date?->format('l, j F Y') ?? 'To be announced'))
                ->line('**Time:** '.($this->event->hasStartTime() ? $this->event->startsAt()?->format('g:i A') : 'To be announced'))
                ->line('**Venue:** '.(filled($this->event->venue) ? $this->event->venue : 'To be announced'));
        }

        if ($this->guest->invitation_token) {
            $mail->action(
                'View invitation',
                route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            );
        } elseif ($this->event->is_public) {
            $mail->action(
                'View invitation',
                route('events.public', ['slug' => $this->event->slug], absolute: true)
            );
        }

        return $this->tagMail($mail->salutation('The '.config('app.name').' Team'));
    }
}
