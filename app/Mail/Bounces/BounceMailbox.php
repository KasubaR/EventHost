<?php

namespace App\Mail\Bounces;

/**
 * The mailbox bounce reports land in (the Return-Path of every outgoing email; plans/email-gaps.md Phase 3).
 */
interface BounceMailbox
{
    /**
     * Hands each unread message, as raw RFC 822 text, to `$handle`. With `$move` the message is then moved to the processed
     * folder; a message whose handler throws is left unread for the next run.
     *
     * @param  callable(string): void  $handle
     * @return int messages handled
     */
    public function process(callable $handle, int $limit, bool $move = true): int;
}
