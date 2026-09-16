<?php

namespace App\Services;

use App\Models\DeviceToken;

/**
 * Default binding (AppServiceProvider::register()) until real Firebase
 * credentials exist — mirrors NullSmsService exactly. Never throws, so a
 * notification that includes 'fcm' in via() degrades to "sent nowhere" (mail
 * still goes out) rather than failing the whole notify() call.
 */
class NullPushNotificationService implements PushNotificationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function send(DeviceToken $token, array $payload): array
    {
        return [
            'status' => 'skipped',
            'provider_message_id' => null,
            'response' => 'Push provider not configured.',
        ];
    }
}
