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
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('The host was not able to confirm your RSVP to '.$this->event->name.'.')
            ->line('Their note: '.$this->note);

        $rsvpUrl = filled($this->guest->invitation_token)
            ? route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            : null;

        if ($rsvpUrl !== null) {
            $message
                ->line('If you believe this is a mistake, you can update your response and try again.')
                ->action('View or change your RSVP', $rsvpUrl);
        }

        return $message->salutation('The '.config('app.name').' Team');
    }
}
