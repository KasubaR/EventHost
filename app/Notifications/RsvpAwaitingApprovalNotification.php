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

/**
 * Host-facing: an Accepted RSVP is being held pending review because the
 * event has require_rsvp_approval on. Sent instead of NewRsvpReceivedNotification
 * (never both — see CommunicationService::dispatchRsvpNotifications()), with an
 * actionable CTA rather than a plain FYI. Same preference keys as
 * NewRsvpReceivedNotification: this is still "an RSVP came in," just one that
 * needs a decision before the guest hears back.
 */
class RsvpAwaitingApprovalNotification extends Notification implements ShouldQueue
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
            'title' => 'RSVP awaiting your review: '.$this->event->name,
            'body' => $this->guest->name.' accepted — approve or decline before their pass goes out.',
            'data' => [
                'type' => 'rsvp_awaiting_approval',
                'event_id' => (string) $this->event->id,
                'guest_id' => (string) $this->guest->id,
            ],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Action needed: '.$this->guest->name.' is awaiting your approval')
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($this->guest->name.' accepted their invitation to '.$this->event->name.'.')
            ->line('You\'ve turned on RSVP approval for this event, so their confirmation and entry pass are on hold until you review it.')
            ->when(
                $this->rsvp->attendee_count > 1,
                fn (MailMessage $m) => $m->line('Attendee count: '.$this->rsvp->attendee_count.'.')
            )
            ->action('Review this RSVP', route('events.guests.index', ['event' => $this->event, 'response' => 'awaiting_approval'], absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
