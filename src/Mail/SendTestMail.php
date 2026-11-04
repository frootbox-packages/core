<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Mail;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Config\Config;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\User\Users;

/**
 * Sends a test mail right away instead of through the queue, so the
 * error of the server shows at once (decision 0269).
 *
 * @implements Action<MailStatus>
 */
#[AsAction('mail.test', 'Sends a short test mail right away, not through the queue, to an address or the own account, and returns how mails leave the server', undoable: false)]
final readonly class SendTestMail implements Action
{
    public function __construct(
        #[Description('The recipient, empty for the address of the own account')]
        #[Email]
        #[Length(max: 254)]
        public ?string $to = null,
    ) {}

    public function handle(Mailer $mailer, CurrentUser $current, Users $users, Config $config, Translator $translator): MailStatus
    {
        $to = null === $this->to || '' === trim($this->to) ? $users->getById($current->id())->email : trim($this->to);
        $status = $mailer->status();
        $site = $config->string('app.name', 'Frootbox');
        $url = $config->get('app.url');

        try {
            $mailer->send(new Message(
                [$to],
                $translator->get('Test mail from {site}', ['site' => $site]),
                $translator->get("This is a test mail from {site}{url}.\n\nIf it arrived, mails of forms reach their recipients the same way.", [
                    'site' => $site,
                    'url' => \is_string($url) && '' !== $url ? ' (' . $url . ')' : '',
                ]),
            ));
        } catch (MailException $error) {
            throw new InvalidInputException('mail.test', ['' => $error->getMessage()]);
        }

        return $status;
    }
}
