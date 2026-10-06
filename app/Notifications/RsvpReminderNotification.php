<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Notifications\Concerns\OffersReminderOptOut;
use App\Support\ShortText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RsvpReminderNotification extends Notification implements ShouldQueue
{
    use OffersReminderOptOut;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public int $daysUntilDeadline,
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
        $when = match (true) {
            $this->daysUntilDeadline <= 0 => 'today',
            $this->daysUntilDeadline === 1 => 'tomorrow',
            default => 'in '.$this->daysUntilDeadline.' days',
        };

        $mail = (new MailMessage)
            ->subject('Reminder: RSVP for '.ShortText::subject($this->event->name))
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('The RSVP deadline for '.$this->event->name.' is '.$when.'.')
            ->line('Please take a moment to respond so the host can plan ahead.');

        // The exact closing time, with its zone: "in 3 days" alone left guests guessing the hour.
        if ($label = $this->event->rsvpDeadlineLabel()) {
            $mail->line('You can respond until '.$label.'.');
        }

        if ($this->guest->invitation_token) {
            $mail->action(
                'Complete your RSVP',
                route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            );
        } elseif ($this->event->is_public) {
            $mail->action(
                'Complete your RSVP',
                route('rsvp.open.show', ['slug' => $this->event->slug], absolute: true)
            );
        }

        return $this->withReminderOptOut($mail, $this->guest, $this->event->name)
            ->salutation('The '.config('app.name').' Team');
    }
}
