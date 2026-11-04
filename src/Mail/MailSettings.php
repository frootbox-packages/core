<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Action\Schema\Title;
use Frootbox\Core\Settings\AsSettings;

/**
 * The SMTP access of the website (decision 0269), "frootbox/core.mail".
 * MAILER_DSN goes first, and only live; in development mails go to the
 * log, so a copy of a live database sends nothing.
 */
#[AsSettings('Email', 'How the website sends mails, e.g. from forms. Without an SMTP server they go out with mail() of PHP.', section: 'mail')]
final readonly class MailSettings
{
    public function __construct(
        #[Title('Sender'), Description('The address mails come from, best one of the SMTP account, e.g. info@example.org. mail.from in config/app.yaml goes first.')]
        #[Length(max: 254)]
        public string $from = '',
        #[Title('SMTP server'), Description('E.g. smtp.example.org. Empty: mails go out with mail() of PHP.')]
        #[Length(max: 253)]
        public string $host = '',
        #[Title('Port'), Description('587 starts encryption with STARTTLS, 465 is encrypted from the start.')]
        #[Range(min: 1, max: 65535)]
        public int $port = 587,
        #[Title('User name'), Description('Usually the email address of the account.')]
        #[Length(max: 254)]
        public string $username = '',
        #[Title('Password'), Description('The password of the SMTP account.')]
        #[Sensitive]
        #[Length(max: 1024)]
        public string $password = '',
    ) {}
}
