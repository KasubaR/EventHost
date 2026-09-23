<?php

namespace App\Services;

use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client;

/**
 * Real implementation, bound in AppServiceProvider once communications.whatsapp.enabled and
 * config('services.twilio.*') are fully set — see plans/whatsapp-invitations.md. Requires the
 * twilio/sdk composer package: `composer require twilio/sdk`.
 */
class TwilioWhatsAppService implements WhatsAppService
{
    /**
     * @param  string  $from  already in Twilio's "whatsapp:+1415..." shape — see
     *                        config('services.twilio.whatsapp_from')
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $from,
    ) {}

    /**
     * @param  array<string, string>  $templateVariables
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendTemplate(string $toE164Phone, string $contentSid, array $templateVariables): array
    {
        try {
            $message = $this->client->messages->create(
                'whatsapp:'.$toE164Phone,
                [
                    'from' => $this->from,
                    'contentSid' => $contentSid,
                    'contentVariables' => json_encode($templateVariables),
                ]
            );
        } catch (TwilioException $e) {
            // Covers auth failures, bad content SID, network errors, etc. — anything Twilio's
            // client rejects before/without queueing a message at all.
            return [
                'status' => 'failed',
                'provider_message_id' => null,
                'response' => substr($e->getMessage(), 0, 500),
            ];
        }

        return $this->mapMessageResult($message->status, $message->sid, $message->errorMessage);
    }

    /**
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendText(string $toE164Phone, string $body): array
    {
        try {
            $message = $this->client->messages->create(
                'whatsapp:'.$toE164Phone,
                [
                    'from' => $this->from,
                    'body' => $body,
                ]
            );
        } catch (TwilioException $e) {
            return [
                'status' => 'failed',
                'provider_message_id' => null,
                'response' => substr($e->getMessage(), 0, 500),
            ];
        }

        return $this->mapMessageResult($message->status, $message->sid, $message->errorMessage);
    }

    /**
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    public function sendMedia(string $toE164Phone, string $mediaUrl, ?string $caption = null): array
    {
        try {
            $params = [
                'from' => $this->from,
                'mediaUrl' => [$mediaUrl],
            ];
            if (is_string($caption) && $caption !== '') {
                $params['body'] = $caption;
            }

            $message = $this->client->messages->create(
                'whatsapp:'.$toE164Phone,
                $params
            );
        } catch (TwilioException $e) {
            return [
                'status' => 'failed',
                'provider_message_id' => null,
                'response' => substr($e->getMessage(), 0, 500),
            ];
        }

        return $this->mapMessageResult($message->status, $message->sid, $message->errorMessage);
    }

    /**
     * create()'s response reflects the send's *initial* queueing state (queued/accepted/sending),
     * not final delivery — that only arrives later via a Twilio status-callback webhook
     * (plans/whatsapp-invitations.md, phase 2, not built yet). 'failed'/'undelivered' can
     * already be known synchronously in some rejection cases; anything else means Twilio
     * accepted the message for delivery.
     *
     * @return array{status: string, provider_message_id: ?string, response: ?string}
     */
    private function mapMessageResult(?string $status, ?string $sid, ?string $errorMessage): array
    {
        $mapped = in_array($status, ['failed', 'undelivered'], true) ? 'failed' : 'sent';

        return [
            'status' => $mapped,
            'provider_message_id' => $sid,
            'response' => $errorMessage,
        ];
    }
}
