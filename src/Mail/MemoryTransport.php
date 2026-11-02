<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

/**
 * Keeps mails in memory, for tests (MAILER_DSN=memory://default) and
 * MAILER_DSN=null://default, which drops them.
 */
final class MemoryTransport implements Transport
{
    /** @var list<array{message: Message, from: string}> */
    private array $sent = [];

    public function __construct(private readonly bool $keep = true) {}

    public function send(Message $message, string $from): void
    {
        if ($this->keep) {
            $this->sent[] = ['message' => $message, 'from' => $from];
        }
    }

    /**
     * @return list<array{message: Message, from: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
