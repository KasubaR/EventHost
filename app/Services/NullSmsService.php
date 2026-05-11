<?php

namespace App\Services;

class NullSmsService implements SmsService
{
    /**
     * @return array{status:string,provider_message_id:?string,response:?string}
     */
    public function send(string $phone, string $message): array
    {
        return [
            'status' => 'skipped',
            'provider_message_id' => null,
            'response' => 'SMS provider not configured.',
        ];
    }
}
