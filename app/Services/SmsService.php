<?php

namespace App\Services;

interface SmsService
{
    /**
     * @return array{status:string,provider_message_id:?string,response:?string}
     */
    public function send(string $phone, string $message): array;
}
