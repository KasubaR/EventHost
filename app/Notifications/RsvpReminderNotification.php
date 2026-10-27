<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Notifications\Concerns\GuardsGuestMail;
use App\Notifications\Concerns\OffersReminderOptOut;
use App\Support\ShortText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RsvpReminderNotification extends Notification implements ShouldQueue
{
    use GuardsGuestMail;
    use OffersReminderOptOut;
    use Queueable;

    /** The deadline's day when this was queued (Event::rsvpDeadlineKeyStamp()). */
    public string $deadlineStamp;

    /** The venue's date when this was queued; "in N days" is worked out again if it is sent on a later day. */
    public string $queuedOn;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public int $daysUntilDeadline,
    ) {
        $this->deadlineStamp = $event->rsvpDeadlineKeyStamp();
        $this->queuedOn = Event::venueToday()->toDateString();
        $this->onQueue('default');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function whyNoLongerRelevant(): ?string
    {
        if ($this->guest->rsvp()->exists()) {
            return 'answered';
        }

        if ($this->guest->hasStoppedEmailReminders()) {
            return 'reminders_stopped';
        }

        // Covers cancelled, paused, unpublished, closed by hand and a deadline already passed.
        if (! $this->event->acceptsRsvps()) {
            return 'rsvp_closed';
        }

        // A host's own reminder may have no deadline at all; only one that had a deadline when queued is stale without it.
        if ($this->deadlineStamp !== 'none' && $this->event->rsvpDeadlineAt() === null) {
            return 'deadline_removed';
        }

        return null;
    }

    /** Days to the deadline as of sending: the queued figure, unless the deadline or the day has changed since. */
    public function daysUntilDeadlineNow(): int
    {
        $deadline = $this->event->rsvpDeadlineAt();

        if ($deadline === null
            || ($this->event->rsvpDeadlineKeyStamp() === $this->deadlineStamp && Event::venueToday()->toDateString() === $this->queuedOn)) {
            return $this->daysUntilDeadline;
        }

        $today = now($this->event->venueTimezone())->startOfDay();

        return max(0, (int) $today->diffInDays($deadline->copy()->startOfDay(), false));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = $this->daysUntilDeadlineNow();

        $when = match (true) {
            $days <= 0 => 'today',
            $days === 1 => 'tomorrow',
            default => 'in '.$days.' days',
        };

        $mail = (new MailMessage)
            ->subject('Reminder: RSVP for '.ShortText::subject($this->event->name))
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('The RSVP deadline for '.$this->event->name.' is '.$when.'.')
            ->line('Please take a moment to respond so the host can plan ahead.');

        // The exact closing time, with its zone: "in 3 days" alone left guests guessing the hour.
        if ($label = $this->event->rsvpDeadlineLabel()) {
            $mail->line('You can respond until '.$label.'.');
        }

        if ($this->guest->invitation_token) {
            $mail->action(
                'Complete your RSVP',
                route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            );
        } elseif ($this->event->is_public) {
            $mail->action(
                'Complete your RSVP',
                route('rsvp.open.show', ['slug' => $this->event->slug], absolute: true)
            );
        }

        return $this->tagMail($this->withReminderOptOut($mail, $this->guest, $this->event->name)
            ->salutation('The '.config('app.name').' Team'));
    }
}
