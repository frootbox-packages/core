<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

/**
 * How mails leave the server, chosen by MAILER_DSN or the settings (decisions 0222, 0269).
 */
interface Transport
{
    /**
     * @throws MailException
     */
    public function send(Message $message, string $from): void;
}
