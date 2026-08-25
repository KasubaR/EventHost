<?php

namespace App\Notifications;

use App\Models\EventStaff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent via on-demand routing (Notification::route('mail', $email)->notify(...))
 * rather than to a User — at invite time the invited address usually has no
 * account yet. Queued on 'high' like WelcomeNotification/EmailChangedNotification,
 * the same queue the scheduled queue:work run covers.
 */
class EventStaffInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public EventStaff $eventStaff,
    ) {
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
        $event = $this->eventStaff->event;
        $inviter = $this->eventStaff->inviter;

        return (new MailMessage)
            ->subject('You\'ve been added as staff on '.$event->name)
            ->greeting('Hello'.($this->eventStaff->name ? ', '.$this->eventStaff->name : '').'!')
            ->line(($inviter->name ?? 'The host').' has invited you to help run "'.$event->name.'" on '.config('app.name').'.')
            ->line('Your role: '.$this->eventStaff->role->label().': '.$this->eventStaff->role->description())
            ->action('Accept invite', route('staff-invitations.show', $this->eventStaff->invite_token, absolute: true))
            ->line('This invite link expires in 7 days.')
            ->salutation('The '.config('app.name').' Team');
    }
}
