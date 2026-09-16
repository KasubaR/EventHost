<?php

namespace App\Services;

/**
 * Default binding (AppServiceProvider::register()) until communications.whatsapp.enabled is true
 * and config('services.twilio.*') is fully populated — mirrors NullSmsService /
 * NullPushNotificationService exactly. Never throws, so code that calls this degrades to
 * "sent nowhere" rather than failing.
 */
class NullWhatsAppService implements WhatsAppService
{
    /**
     * @param  array<string, string>  $templateVariables
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
    {
        return [
            'status' => 'skipped',
            'provider_message_id' => null,
            'response' => 'WhatsApp provider not configured.',
        ];
    }
}
