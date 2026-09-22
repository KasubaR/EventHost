<?php

namespace App\Services;

use App\Models\DeviceToken;

/**
 * Slice E (Android push). Same shape convention as SmsService::send() — a
 * structured result instead of throwing, so App\Notifications\Channels\FcmChannel
 * can log a failure per-device without one bad token failing the whole send.
 */
interface PushNotificationService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function send(DeviceToken $token, array $payload): array;
}
