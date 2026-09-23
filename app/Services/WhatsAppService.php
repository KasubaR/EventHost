<?php

namespace App\Services;

/**
 * Server-initiated WhatsApp sends via an approved Content Template — see
 * plans/whatsapp-invitations.md. Distinct from App\Support\WhatsAppInviteLink, which builds a
 * wa.me deeplink the host's own WhatsApp app sends from; this interface is for a business-initiated
 * send that requires Meta template approval (Twilio, or any future provider, behind it).
 *
 * sendText() / sendMedia() are for free-form session messages only (within Meta's 24h customer-care
 * window after the guest replies) — e.g. RSVP confirmation + entry-pass QR. Outside that window
 * Meta rejects plain body/media sends; use sendTemplate() instead.
 *
 * Same shape convention as SmsService::send() / PushNotificationService::send() — a structured
 * result instead of throwing, so CommunicationService can log a failure without an unrelated
 * Twilio exception surfacing as an uncaught 500.
 */
interface WhatsAppService
{
    /**
     * @param  string  $toE164Phone  e.g. "+260977123456" — see App\Support\ZambianPhone::toE164()
     * @param  string  $contentSid  approved Twilio Content Template SID (HX...)
     * @param  array<string, string>  $templateVariables  keyed "1", "2", ... per the template's
     *                                                    numbered placeholders (Twilio Content API;
     *                                                    invitation template uses "1".."7", where
     *                                                    "7" is the IMAGE header path after the host)
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array;

    /**
     * Free-form body — session window only.
     *
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendText(string $toE164Phone, string $body): array;

    /**
     * Free-form image (and optional caption) — session window only. $mediaUrl must be a
     * publicly reachable HTTPS URL (Twilio fetches it).
     *
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendMedia(string $toE164Phone, string $mediaUrl, ?string $caption = null): array;
}
