<?php

namespace App\Notifications;

use App\Enums\PublicRegistrationStatus;
use App\Enums\TicketingStatus;
use App\Models\AdminActivityLog;
use App\Models\AdminHelpRequest;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Closes the loop on a help request: what our team did on the client's account, where the
 * event is, and what is left for the client to do themselves. Plan: plans/admin-create-events.md (Step 6).
 */
class HelpRequestCompletedNotification extends Notification implements ShouldQueue
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
        $entries = AdminActivityLog::query()
            ->where('help_request_id', $this->helpRequest->id)
            ->whereNotIn('action', ['session_started', 'session_ended'])
            ->with('event:id,name')
            ->orderBy('id')
            ->get();

        $events = Event::query()
            ->whereIn('id', $entries->pluck('event_id')->filter()->unique())
            ->get();

        $mail = (new MailMessage)
            ->subject('Your request is complete')
            ->greeting('Hello!')
            ->line('Our team has finished the work you asked for. Nobody from our team can open your account any more.');

        if ($entries->isNotEmpty()) {
            $mail->line('What we did:');

            foreach ($entries->unique(fn (AdminActivityLog $e): string => $e->action.'|'.$e->event_id)->take(15) as $entry) {
                $mail->line('- '.$entry->label().($entry->event ? ': '.$entry->event->name : ''));
            }
        }

        foreach ($events as $event) {
            $mail->line('**'.$event->name.'**: '.$this->nextStep($event));
        }

        $first = $events->first();

        return $mail
            ->action(
                $events->count() === 1 ? 'Open your event' : 'Go to your events',
                $first !== null && $events->count() === 1
                    ? route('events.show', $first, absolute: true)
                    : route('events.index', absolute: true),
            )
            ->line('You can see everything we did under "Get help" in your account. If something looks wrong, reply to this email or contact support.')
            ->salutation('The '.config('app.name').' Team');
    }

    /**
     * The one thing the client still has to do for this event. Money and the final go-live
     * are always theirs, so this is nearly always a payment or a publish.
     */
    private function nextStep(Event $event): string
    {
        if ($event->isTicketed()) {
            return match ($event->ticketing_status) {
                TicketingStatus::PendingReview => 'it is waiting for EventHost to review ticket sales. We will email you once it is decided.',
                TicketingStatus::Approved => 'ticket sales are approved. Check your ticket types and share the link.',
                default => 'add anything still missing and submit it for review so ticket sales can start.',
            };
        }

        if ($event->isFreeRegistration()) {
            return match ($event->public_registration_status) {
                PublicRegistrationStatus::Approved => $event->public_registration_quote_paid_at === null
                    ? 'it is approved. Pay the quoted amount from the event page to put it live.'
                    : 'it is live. Share the link with your guests.',
                PublicRegistrationStatus::PendingReview => 'it is waiting for EventHost to review it. We will email you once it is decided.',
                default => 'review it and submit it for approval from the event page.',
            };
        }

        return $event->is_published
            ? 'it is live. Share your invitation link and add your guests.'
            : 'preview it and, when you are happy, publish it yourself. Publishing uses one event credit.';
    }
}
