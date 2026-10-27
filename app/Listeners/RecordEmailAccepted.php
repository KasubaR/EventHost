<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * The mail server took a guest email (plans/email-gaps.md). The furthest the app can see: a bounce, if any, arrives later
 * through mail:process-bounces.
 */
class RecordEmailAccepted
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! property_exists($event->notification, 'notificationLogId')) {
            return;
        }

        $id = $event->notification->notificationLogId;
        if (! is_int($id)) {
            return;
        }

        NotificationLog::query()
            ->whereKey($id)
            ->whereNull('delivery_status')
            ->update(['delivery_status' => NotificationLog::DELIVERY_ACCEPTED]);
    }
}
