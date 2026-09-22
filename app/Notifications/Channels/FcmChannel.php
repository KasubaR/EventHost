<?php

namespace App\Notifications\Channels;

use App\Services\PushNotificationService;
use Illuminate\Notifications\Notification;

/**
 * Slice E (Android push) — additive to 'mail', never a replacement for it.
 * A notification opts in by returning an array from toFcm($notifiable) (same
 * convention as toMail()) and including 'fcm' in via() only when it actually
 * has something to send (see NewRsvpReceivedNotification for the one wired
 * example). Sends to every one of the notifiable's registered devices —
 * a failure on one token is logged but never stops the others or bubbles up
 * as a failed notify() call, since mail must not be affected by a push
 * provider being unconfigured or a single stale token.
 */
class FcmChannel
{
    public function __construct(private readonly PushNotificationService $pushService) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        if (! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $payload = $notification->toFcm($notifiable);

        foreach ($notifiable->deviceTokens()->get() as $token) {
            $this->pushService->send($token, $payload);
        }
    }
}
