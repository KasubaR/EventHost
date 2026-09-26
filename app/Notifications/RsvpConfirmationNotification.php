<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\Guest;
use App\Models\Rsvp;
use App\Services\GuestPassImageService;
use App\Services\GuestPassPdfService;
use App\Services\QrCodeService;
use App\Support\GuestPassCard;
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
            ->subject('RSVP recorded: '.$this->event->name)
            ->greeting('Hello, '.$this->guest->name.'!')
            ->line('Thanks for letting us know about '.$this->event->name.'.')
            ->line('Your response: '.$statusLabel.'.')
            ->when(
                $this->rsvp->status->countsTowardGuestLimit(),
                fn (MailMessage $m) => $m->line('Guests attending: '.$this->rsvp->attendee_count.'.')
            );

        $rsvpUrl = filled($this->guest->invitation_token)
            ? route('rsvp.token.show', ['token' => $this->guest->invitation_token], absolute: true)
            : null;

        // Same eligibility rule as the web entry pass (RsvpController::guestHasEntryPass()):
        // accepted, has a token, host's plan includes check-in tools. That guest's mail is
        // about the pass, so the button opens it and changing the RSVP drops to a link.
        if ($this->guest->hasEntryPassFor($this->rsvp, $this->event)) {
            $this->attachPass($message);

            $message
                ->action('View your pass', route('rsvp.token.pass', ['token' => $this->guest->invitation_token], absolute: true))
                ->line('If anything changes, you can [update your response]('.$rsvpUrl.') anytime.');
        } elseif ($rsvpUrl !== null) {
            $message
                ->line('If anything changes, you can update your response anytime using this link:')
                ->action('View or change your RSVP', $rsvpUrl);
        } else {
            $message->line('If anything changes, submit again using the same RSVP option you used before.');
        }

        return $message->salutation('The '.config('app.name').' Team');
    }

    /**
     * Attach the invitation pass as a PDF and as a card image (plans/invitation-pass-card.md
     * Phase 4). Each is produced independently — a renderer that cannot run (GD without
     * FreeType, a missing font) must never cost the guest the confirmation, or leave them
     * with no way in: if neither works the mail degrades to the bare QR it always carried.
     */
    private function attachPass(MailMessage $message): void
    {
        $slug = Str::slug($this->event->name) ?: 'invitation';
        $attached = 0;

        try {
            $card = GuestPassCard::for($this->guest, $this->event, $this->rsvp);
        } catch (\Throwable $e) {
            report($e);
            $card = null;
        }

        if ($card !== null) {
            foreach ([
                ['pdf', 'application/pdf', fn (): string => app(GuestPassPdfService::class)->render($this->guest, $card)],
                ['png', 'image/png', fn (): string => app(GuestPassImageService::class)->render($this->guest, $card)],
            ] as [$extension, $mime, $render]) {
                try {
                    $message->attachData($render(), $slug.'-pass.'.$extension, ['mime' => $mime]);
                    $attached++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        if ($attached > 0) {
            $message->line('Your invitation pass is attached as a PDF and as an image. Show either one at the door.');

            return;
        }

        $qrUrl = $this->guest->checkInQrUrl();
        if ($qrUrl !== null) {
            $message
                ->line('Your entry QR code is attached. Show it at the door.')
                ->attachData(
                    app(QrCodeService::class)->png($qrUrl),
                    Str::slug($this->guest->name).'-entry-pass.png',
                    ['mime' => 'image/png']
                );
        }
    }
}
