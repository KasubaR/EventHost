<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewRsvpReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public Rsvp $rsvp,
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
        $statusLabel = $this->rsvp->status->label();

        return (new MailMessage)
            ->subject('New RSVP: '.$this->guest->name.' — '.$this->event->name)
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($this->guest->name.' just responded to '.$this->event->name.'.')
            ->line('Response: '.$statusLabel.'.')
            ->when(
                $this->rsvp->status->countsTowardGuestLimit(),
                fn (MailMessage $m) => $m->line('Attendee count: '.$this->rsvp->attendee_count.'.')
            )
            ->action('View event', route('events.show', ['event' => $this->event], absolute: true))
            ->line('You can track responses from your event dashboard.')
            ->salutation(config('app.name'));
    }
}
