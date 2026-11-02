<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

/**
 * Sends with PHP's mail(), which works on normal webspace
 * (MAILER_DSN=native://default).
 */
final readonly class NativeTransport implements Transport
{
    public function send(Message $message, string $from): void
    {
        $headers = [
            'From' => $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
        ];

        if (null !== $message->replyTo) {
            $headers['Reply-To'] = $message->replyTo;
        }

        $body = rtrim(chunk_split(base64_encode($message->text), 76, "\r\n"));
        $subject = mb_encode_mimeheader($message->subject, 'UTF-8', 'B', "\r\n");

        if (!mail(implode(', ', $message->to), $subject, $body, $headers)) {
            throw new MailException('PHP could not hand the mail over, see the mail log of the server.');
        }
    }
}
