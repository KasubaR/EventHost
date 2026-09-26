<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One digest per host: the deleted events that are about to be permanently
 * removed, with the date and a way back to the list to restore them. A host who
 * deleted twenty events gets one email, not twenty. Plan: plans/event-retention.md §4b.
 *
 * A service notice about irreversible removal of data, not marketing and not an
 * event reminder — deliberately not tied to any notification_preferences toggle.
 *
 * Carries plain arrays rather than Event models: the events are soft-deleted, and a
 * queued notification should describe the state that was warned about, not whatever
 * the row looks like when the worker gets to it.
 */
class HostPurgeWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    /**
     * @param  list<array{name: string, deleted_on: string, purge_on: string, list_url: string}>  $events
     */
    public function __construct(public array $events)
    {
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
        $count = count($this->events);
        $soonest = $this->events[0]['purge_on'] ?? '';

        $mail = (new MailMessage)
            ->subject($count === 1
                ? 'A deleted event will be permanently removed on '.$soonest
                : $count.' deleted events will be permanently removed soon')
            ->greeting('Hello, '.$notifiable->name.'!')
            ->line($count === 1
                ? 'An event you deleted is about to be permanently removed.'
                : 'Some events you deleted are about to be permanently removed.')
            ->line('When that happens, the event is gone for good — together with its guest list, RSVPs and uploaded photos and media. It cannot be recovered afterwards. Until then you can restore it from **Recently deleted**.');

        foreach ($this->events as $event) {
            $mail->line('**'.$event['name'].'** — deleted '.$event['deleted_on'].', removed on **'.$event['purge_on'].'**. [Restore it]('.$event['list_url'].')');
        }

        return $mail
            ->action('Open Recently deleted', $this->events[0]['list_url'] ?? url('/events'))
            ->line('If you meant to delete '.($count === 1 ? 'it' : 'them').', there is nothing to do.')
            ->salutation('The '.config('app.name').' Team');
    }
}
