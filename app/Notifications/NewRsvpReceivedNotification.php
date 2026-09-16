<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Notifications\Channels\FcmChannel;
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
     * Both channels are independently gated here (not just at the
     * CommunicationService::notifyHostNewRsvp() call site, which only checks
     * "is either channel wanted at all" before bothering to notify) — a host
     * with email off but push_rsvp_updates on must still get the push, and
     * vice versa. FcmChannel::class (not the string 'fcm') is how Laravel
     * resolves a custom channel with no separate Notification::extend()
     * registration — see FcmChannel's own docblock.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ((bool) ($notifiable->notification_preferences['email_rsvp_updates'] ?? true)) {
            $channels[] = 'mail';
        }

        if ((bool) ($notifiable->notification_preferences['push_rsvp_updates'] ?? true)
            && $notifiable->deviceTokens()->exists()) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'New RSVP: '.$this->event->name,
            'body' => $this->guest->name.' responded '.$this->rsvp->status->label().'.',
            'data' => [
                'type' => 'new_rsvp',
                'event_id' => (string) $this->event->id,
                'guest_id' => (string) $this->guest->id,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabel = $this->rsvp->status->label();

        return (new MailMessage)
            ->subject('New RSVP from '.$this->guest->name.': '.$this->event->name)
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($this->guest->name.' just responded to '.$this->event->name.'.')
            ->line('Response: '.$statusLabel.'.')
            ->when(
                $this->rsvp->status->countsTowardGuestLimit(),
                fn (MailMessage $m) => $m->line('Attendee count: '.$this->rsvp->attendee_count.'.')
            )
            ->action('View event', route('events.show', ['event' => $this->event], absolute: true))
            ->line('You can track responses from your event dashboard.')
            ->salutation('The '.config('app.name').' Team');
    }
}
