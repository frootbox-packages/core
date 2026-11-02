<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Queue\AsJob;
use Frootbox\Core\Queue\Job;

/**
 * Sends a mail from the queue, so a request does not wait for the
 * mail server and a failed attempt runs again (decision 0222).
 */
#[AsJob('mail.send', 'Sends an email', attempts: 6)]
final readonly class SendMail implements Job
{
    /**
     * @param list<string> $to
     */
    public function __construct(
        #[ListOf('string')]
        public array $to,
        public string $subject,
        public string $text,
        public ?string $replyTo = null,
    ) {}

    public static function of(Message $message): self
    {
        return new self($message->to, $message->subject, $message->text, $message->replyTo);
    }

    public function handle(Mailer $mailer): void
    {
        $mailer->send(new Message($this->to, $this->subject, $this->text, $this->replyTo));
    }
}
