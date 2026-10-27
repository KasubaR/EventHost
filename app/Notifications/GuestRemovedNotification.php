<?php

namespace App\Notifications;

use App\Models\Event;
use App\Notifications\Concerns\GuardsGuestMail;
use App\Support\ShortText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Guest-facing: the host removed them from the guest list. Sent only when the host presses "Tell them" on a removed guest
 * (plans/guest-deletion-gaps.md Phase 5), never automatically. Carries the name as a string, not a Guest model: the guest
 * no longer exists, so a model here would fail to restore on the queue (see GuardsGuestMail).
 */
class GuestRemovedNotification extends Notification implements ShouldQueue
{
    use GuardsGuestMail;
    use Queueable;

    public function __construct(
        public Event $event,
        public string $guestName,
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
            ->subject('Your invitation was removed: '.ShortText::subject($this->event->name))
            ->greeting('Hello, '.$this->guestName.'!')
            ->line('The host of '.$this->event->name.' has removed you from the guest list.')
            ->line('The RSVP link and entry pass you were sent no longer work.');

        $phone = $this->event->hostContactPhone();
        $message->line($phone !== null && $phone !== ''
            ? 'If you think this is a mistake, please contact the host on '.$phone.'.'
            : 'If you think this is a mistake, please contact the host.');

        return $this->tagMail($message->salutation('The '.config('app.name').' Team'));
    }
}
