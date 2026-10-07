<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Support\ShortText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Guest-facing: an extra seat (a plus-one) was added to an RSVP the host had already approved. Nothing the guest holds
 * is taken back: the pass stays valid for the approved seats, and only the extra seat waits for the host.
 * plans/rsvp-status-changes.md Phase 3.
 */
class RsvpExtraSeatPendingNotification extends Notification implements ShouldQueue
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
        $approved = $this->rsvp->approvedSeatsOnFile();

        $message = (new MailMessage)
            ->subject('Your extra seat is waiting for the host: '.ShortText::subject($this->event->name))
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('We have passed your request for an extra seat at '.$this->event->name.' to the host.')
            ->line('Your pass is still valid for '.$approved.' '.($approved === 1 ? 'seat' : 'seats').'. The extra seat will be added once the host approves it.');

        if (filled($this->guest->invitation_token)) {
            $message->action('View your RSVP', route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true));
        }

        return $message->salutation('The '.config('app.name').' Team');
    }
}
