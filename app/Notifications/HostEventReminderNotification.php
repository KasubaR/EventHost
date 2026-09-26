<?php

namespace App\Notifications;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Host-facing "your event is coming up" email. Distinct from
 * RsvpReminderNotification (guest-facing, before the RSVP deadline, Pro+) —
 * this goes to the event owner and is not tier-gated. Gated by the host's
 * `email_event_reminders` preference — see
 * CommunicationService::notifyHostEventReminder().
 */
class HostEventReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public Event $event,
        public int $daysUntilEvent,
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
        $event = $this->event;

        $when = $this->daysUntilEvent === 1 ? 'tomorrow' : 'in '.$this->daysUntilEvent.' days';

        $start = $event->startsAt();
        $dateLine = $start?->format('l, j F Y');
        if ($dateLine !== null && $event->hasStartTime()) {
            $dateLine .= ' at '.$start->format('H:i');
        }

        $mail = (new MailMessage)
            ->subject('Reminder: '.$event->name.' is '.$when)
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($event->name.' is '.$when.'.');

        if ($dateLine !== null) {
            $mail->line('When: '.$dateLine);
        }

        $where = collect([$event->venue, $event->location_name])
            ->filter(fn ($part): bool => is_string($part) && trim($part) !== '')
            ->unique()
            ->join(', ');
        if ($where !== '') {
            $mail->line('Where: '.$where);
        }

        if ($event->isInvitation()) {
            $mail->line('Guests confirmed so far: '.$event->acceptedAttendeeHeadcount().'.');
        }

        return $mail
            ->action('View your event', route('events.show', ['event' => $event], absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
