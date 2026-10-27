<?php

namespace App\Mail\Bounces;

use Webklex\PHPIMAP\ClientManager;

/**
 * Reads the cPanel bounce mailbox over IMAP with webklex/php-imap (pure PHP: PHP 8.4 dropped ext-imap from core).
 */
class ImapBounceMailbox implements BounceMailbox
{
    /**
     * @param  array<string, mixed>  $config  config('mail.bounce_imap')
     */
    public function __construct(private readonly array $config) {}

    public function process(callable $handle, int $limit, bool $move = true): int
    {
        $client = (new ClientManager)->make([
            'host' => $this->config['host'],
            'port' => (int) ($this->config['port'] ?? 993),
            'encryption' => $this->config['encryption'] ?? 'ssl',
            'validate_cert' => (bool) ($this->config['validate_cert'] ?? true),
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'protocol' => 'imap',
        ]);

        $client->connect();

        try {
            $inbox = $client->getFolder((string) ($this->config['folder'] ?? 'INBOX'));
            if ($inbox === null) {
                return 0;
            }

            $processedPath = (string) ($this->config['processed_folder'] ?? 'Processed');
            if ($move && $client->getFolder($processedPath) === null) {
                $client->createFolder($processedPath);
            }

            $handled = 0;
            $messages = $inbox->query()->whereUnseen()->leaveUnread()->limit($limit)->get();

            foreach ($messages as $message) {
                $raw = ($message->getHeader()?->raw ?? '')."\r\n\r\n".$message->getRawBody();

                $handle($raw);
                $handled++;

                if ($move) {
                    $message->move($processedPath);
                }
            }

            return $handled;
        } finally {
            $client->disconnect();
        }
    }
}
