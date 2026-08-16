<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\QrCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class RsvpConfirmationNotification extends Notification implements ShouldQueue
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
        $statusLabel = $this->rsvp->status->attendanceLabel();

        $message = (new MailMessage)
            ->subject('RSVP recorded — '.$this->event->name)
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('Thanks for letting us know about '.$this->event->name.'.')
            ->line('Your response: '.$statusLabel.'.')
            ->when(
                $this->rsvp->status->countsTowardGuestLimit(),
                fn (MailMessage $m) => $m->line('Guests attending: '.$this->rsvp->attendee_count.'.')
            )
            ->when(
                filled($this->guest->invitation_token),
                fn (MailMessage $m) => $m
                    ->line('If anything changes, you can update your response anytime using this link:')
                    ->action(
                        'View or change your RSVP',
                        route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
                    ),
                fn (MailMessage $m) => $m
                    ->line('If anything changes, submit again using the same RSVP option you used before.')
            );

        // Same eligibility rule as the web entry pass (RsvpController::guestHasEntryPass()):
        // accepted, has a token, host's plan includes check-in tools. Attached as a PNG
        // (see QrCodeService::png()) — most mail clients strip or refuse to inline SVGs.
        if ($this->guest->hasEntryPassFor($this->rsvp, $this->event)) {
            $qrUrl = $this->guest->checkInQrUrl();

            if ($qrUrl !== null) {
                $message
                    ->line('Your entry QR code is attached — show it at the door.')
                    ->attachData(
                        app(QrCodeService::class)->png($qrUrl),
                        Str::slug($this->guest->name).'-entry-pass.png',
                        ['mime' => 'image/png']
                    );
            }
        }

        return $message->salutation(config('app.name'));
    }
}
