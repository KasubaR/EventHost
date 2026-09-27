<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Notifications\Concerns\OffersReminderOptOut;
use App\Support\EventReminderBuckets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * "Your event is coming up" email to an Accepted guest — 7 days, 1 day and the day of
 * (plans/guest-email-reminders.md). The wording of the lead line is EventReminderBuckets::lead(), the same
 * text the WhatsApp reminder sends.
 *
 * No attachments on purpose: the confirmation email already carried the pass, and the pass page has both
 * downloads. Rendering a PDF and a PNG again for every guest, three times, would be wasted work.
 */
class GuestEventReminderNotification extends Notification implements ShouldQueue
{
    use OffersReminderOptOut;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public Event $event,
        public Guest $guest,
        public string $bucket,
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
        $name = $this->event->name;

        $subject = match ($this->bucket) {
            EventReminderBuckets::BUCKET_7 => 'One week to go: '.$name,
            EventReminderBuckets::BUCKET_1 => 'Tomorrow: '.$name,
            EventReminderBuckets::BUCKET_0 => 'Today: '.$name,
            default => 'Coming up: '.$name,
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Hello, '.(filled($this->guest->name) ? $this->guest->name : 'there').'!')
            ->line(EventReminderBuckets::lead($name, $this->bucket))
            ->line('**Date:** '.($this->event->event_date?->format('j F Y') ?? 'To be announced'))
            ->line('**Time:** '.($this->event->hasStartTime() ? Carbon::parse($this->event->event_time)->format('H:i') : 'TBA'))
            ->line('**Venue:** '.(filled($this->event->venue) ? $this->event->venue : 'Venue TBA'));

        if ($this->event->latitude !== null && $this->event->longitude !== null) {
            $mail->line('[Open the venue in Google Maps](https://www.google.com/maps?q='.$this->event->latitude.','.$this->event->longitude.')');
        }

        $rsvpUrl = $this->guest->personalRsvpUrl();
        $rsvp = $this->guest->rsvp;

        // Same eligibility as the web entry pass and the confirmation email: accepted, has a token, and the
        // host's plan includes check-in tools. That guest wants the pass at the door, so it is the button.
        if ($rsvp !== null && $this->guest->hasEntryPassFor($rsvp, $this->event)) {
            $mail->action('View your pass', (string) $this->guest->passPageUrl());
        } elseif ($rsvpUrl !== null) {
            $mail->action('View invitation details', $rsvpUrl);
        } else {
            $mail->action('View invitation details', route('events.public', ['slug' => $this->event->slug], absolute: true));
        }

        if ($rsvpUrl !== null && $this->event->isRsvpOpen()) {
            $mail->line('Can\'t make it any more? You can [update your response]('.$rsvpUrl.') any time.');
        }

        $mail->line('You are receiving this because you accepted the invitation to '.$name.'.');

        return $this->withReminderOptOut($mail, $this->guest, $name)
            ->salutation('The '.config('app.name').' Team');
    }
}
