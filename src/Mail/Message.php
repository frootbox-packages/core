<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

/**
 * A plain text email (decision 0222).
 */
final readonly class Message
{
    /**
     * @param list<string> $to
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $text,
        public ?string $replyTo = null,
    ) {}
}
