<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Psr\Log\LoggerInterface;

/**
 * Writes mails to the log instead of sending them, for development
 * (MAILER_DSN=log://default).
 */
final readonly class LogTransport implements Transport
{
    public function __construct(private LoggerInterface $logger) {}

    public function send(Message $message, string $from): void
    {
        $this->logger->info('Mail "{subject}" to {to}', [
            'subject' => $message->subject,
            'to' => implode(', ', $message->to),
            'from' => $from,
            'replyTo' => $message->replyTo,
            'text' => $message->text,
        ]);
    }
}
