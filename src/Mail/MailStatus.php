<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

/**
 * How mails leave the server right now (decision 0269), never with a password.
 */
final readonly class MailStatus
{
    public const string ENVIRONMENT = 'environment';
    public const string SETTINGS = 'settings';
    public const string STANDARD = 'default';

    public function __construct(
        /** smtp, native, log, null or memory */
        public string $transport,
        /** environment (MAILER_DSN), settings or default */
        public string $source,
        public string $from,
        public ?string $host = null,
        public ?int $port = null,
        /** An SMTP server is set in the settings, but MAILER_DSN or the development mode goes first */
        public bool $settingsIgnored = false,
    ) {}
}
