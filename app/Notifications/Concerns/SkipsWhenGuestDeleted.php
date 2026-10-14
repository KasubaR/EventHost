<?php

namespace App\Notifications\Concerns;

use App\Models\Guest;
use App\Models\Rsvp;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A queued guest notification must not fail the job when the host deletes the guest before the worker reaches it
 * (plans/guest-deletion-gaps.md Phase 1).
 *
 * `$deleteWhenMissingModels` does nothing here: Laravel reads it from the queued job class
 * (SendQueuedNotifications), never from the notification, so the job would land in failed_jobs. Instead the
 * notification restores a missing Guest or Rsvp as an empty, unsaved stand-in and `shouldSend()` refuses it, so the
 * job finishes quietly and no mail goes to someone who was removed. A missing Event still fails as before.
 *
 * The notification_logs row is not touched: it is written as `sent` when the notification is handed to the queue,
 * and its guest_id is already nulled by the foreign key.
 */
trait SkipsWhenGuestDeleted
{
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $this->guest->exists) {
            return false;
        }

        return ! property_exists($this, 'rsvp') || $this->rsvp->exists;
    }

    protected function getRestoredPropertyValue($value)
    {
        try {
            return parent::getRestoredPropertyValue($value);
        } catch (ModelNotFoundException $e) {
            $class = $e->getModel();

            if (is_string($class) && (is_a($class, Guest::class, true) || is_a($class, Rsvp::class, true))) {
                return new $class;
            }

            throw $e;
        }
    }
}
