<?php

namespace App\Notifications;

use App\Models\AdminHelpRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the client a named member of our team has picked up their request — and, because
 * that is also the moment access to their account opens, says so plainly.
 */
class HelpRequestClaimedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public AdminHelpRequest $helpRequest,
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
        $adminName = $this->helpRequest->assignedAdmin?->name ?? 'A member of our team';
        $until = $this->helpRequest->access_expires_at?->timezone(config('events.timezone'))->format('j M Y');

        return (new MailMessage)
            ->subject($adminName.' is working on your request')
            ->greeting('Hello!')
            ->line($adminName.' has picked up your request for help and will set things up on your account.')
            ->line('Our team can only work on your account while this request is open'
                .($until ? ', which is until '.$until : '').'. You can cancel it at any time and access ends straight away.')
            ->line('We never change your password or email address, and any payments are always yours to make.')
            ->action('View your request', route('help-request.show', absolute: true))
            ->salutation('The '.config('app.name').' Team');
    }
}
