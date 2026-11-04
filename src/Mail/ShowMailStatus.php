<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;

/**
 * @implements Query<MailStatus>
 */
#[AsQuery('mail.status', 'Returns how mails leave the server: transport, where it is set, SMTP server and sender, never a password')]
final readonly class ShowMailStatus implements Query
{
    public function handle(Mailer $mailer): MailStatus
    {
        return $mailer->status();
    }
}
