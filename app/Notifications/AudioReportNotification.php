<?php

namespace App\Notifications;

use App\Models\AudioReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AudioReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(private readonly AudioReport $report)
    {
        $this->onQueue('high');
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
        $r = $this->report;

        return (new MailMessage)
            ->subject('[Copyright report] '.($r->event_name ?: 'Invitation music'))
            ->replyTo($r->reporter_email, $r->reporter_name)
            ->greeting('New report about invitation music')
            ->line('**From:** '.$r->reporter_name.' <'.$r->reporter_email.'>')
            ->line('**Event:** '.($r->event_name ?: 'unknown'))
            ->line('**Rights holder:** '.($r->rights_holder ?: 'not given'))
            ->line('**Details**')
            ->line($r->details)
            ->action('Review in the admin panel', route('admin.audio-reports.index'))
            ->salutation('The '.config('app.name').' Team');
    }
}
