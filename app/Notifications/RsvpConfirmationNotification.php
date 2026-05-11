<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RsvpConfirmationNotification extends Notification implements ShouldQueue
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
            ->subject('RSVP recorded — '.$this->event->name)
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('Thanks for letting us know about '.$this->event->name.'.')
            ->line('Your response: '.$statusLabel.'.')
            ->when(
                $this->rsvp->status->countsTowardGuestLimit(),
                fn (MailMessage $m) => $m->line('Guests attending: '.$this->rsvp->attendee_count.'.')
            )
            ->when(
                filled($this->guest->invitation_token),
                fn (MailMessage $m) => $m
                    ->line('If anything changes, you can update your response anytime using this link:')
                    ->action(
                        'View or change your RSVP',
                        route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
                    ),
                fn (MailMessage $m) => $m
                    ->line('If anything changes, submit again using the same RSVP option you used before.')
            )
            ->salutation(config('app.name'));
    }
}
