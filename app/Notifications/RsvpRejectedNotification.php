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
 * Guest-facing: the host declined an Accepted RSVP under require_rsvp_approval.
 * No pass was ever generated for this response — only RsvpApprovalService::approve()
 * triggers the confirmation + pass send, and reject() never does.
 */
class RsvpRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public Rsvp $rsvp,
        public string $note,
        public bool $extraSeatOnly = false,
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
            ->subject('Update on your RSVP: '.ShortText::subject($this->event->name))
            ->greeting('Hello, '.$this->guest->name.'!');

        if ($this->extraSeatOnly) {
            $seats = $this->rsvp->attendee_count;
            $message
                ->line('The host was not able to confirm the extra seat you asked for at '.$this->event->name.'.')
                ->line('Your RSVP for '.$seats.' '.($seats === 1 ? 'seat' : 'seats').' still stands, and your pass is unchanged.');
        } else {
            $message->line('The host was not able to confirm your RSVP to '.$this->event->name.'.');
        }

        $message->line('Their note: '.$this->note);

        $rsvpUrl = filled($this->guest->invitation_token)
            ? route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            : null;

        if ($rsvpUrl !== null) {
            // The host's decision is final, so this never invites another request.
            $message
                ->line('If you have questions, please contact the host.')
                ->action('View your RSVP', $rsvpUrl);
        }

        return $message->salutation('The '.config('app.name').' Team');
    }
}
