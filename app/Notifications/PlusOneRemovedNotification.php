<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Guest-facing: the host took a confirmed RSVP back from two seats to one. Sent only by the host's
 * "Remove plus-one" action, never when plus-ones are merely switched off.
 */
class PlusOneRemovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public Guest $guest,
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
        $message = (new MailMessage)
            ->subject('Update on your RSVP: '.$this->event->name)
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('The host has updated your RSVP to '.$this->event->name.'. It now covers you only; a plus-one is no longer included.')
            ->line('Your own place is unchanged.');

        $rsvpUrl = filled($this->guest->invitation_token)
            ? route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            : null;

        if ($rsvpUrl !== null) {
            $message->action('View your RSVP', $rsvpUrl);
        }

        return $message->salutation('The '.config('app.name').' Team');
    }
}
