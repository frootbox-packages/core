<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Sensitive;
use Frootbox\Core\Auth\CurrentUser;
use Frootbox\Core\Auth\Passwords;
use Frootbox\Core\Config\Config;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Mail\Message;
use Frootbox\Core\Mail\SendMail;
use Frootbox\Core\Queue\Queue;
use Frootbox\Core\User\Users;

/**
 * Changes the own email after the current password (decision 0341): with
 * a link to the new address when the website sends mails, at once
 * otherwise. The old address hears about it.
 *
 * @implements Action<EmailChange>
 */
#[AsAction('account.request-email-change', 'Changes the email of the own account after the current password: mails a link to the new address to confirm it, or changes it at once when the website sends no mails', signedIn: true, undoable: false)]
final readonly class RequestEmailChange implements Action
{
    public function __construct(
        #[Email]
        #[Length(max: 255)]
        public string $email,
        #[Sensitive]
        #[Length(max: 4096)]
        public string $password,
    ) {}

    public function handle(Users $users, Passwords $passwords, CurrentUser $current, EmailChanges $changes, Queue $queue, Translator $translator, Config $config): EmailChange
    {
        $user = $users->getById($current->id());
        $email = Users::normalizeEmail($this->email);

        if (!$passwords->verify($this->password, $users->passwordHash($user->id))) {
            throw new InvalidInputException('account.request-email-change', ['/password' => 'is wrong.']);
        }

        if ($email === $user->email) {
            throw new InvalidInputException('account.request-email-change', ['/email' => 'is your email already.']);
        }

        if (null !== $users->findByEmail($email)) {
            throw new InvalidInputException('account.request-email-change', ['/email' => 'is already in use.']);
        }

        if (!$changes->available()) {
            $users->update($user->id, ['email' => $email]);

            return new EmailChange($email, false);
        }

        $language = $user->language ?? $translator->standard();
        $site = $config->string('app.name', 'Frootbox');
        $queue->push(SendMail::of(new Message(
            [$email],
            $translator->in($language, 'Confirm your new email for {site}', [], ['site' => $site]),
            $translator->in($language, "Hello {name},\n\nconfirm with this link within {hours} hours that you sign in to {site} with this email from now on:\n\n{link}\n\nIf it was not you, ignore this mail. Nothing changes.\n", [], [
                'name' => $user->name,
                'site' => $site,
                'hours' => EmailChanges::HOURS,
                'link' => $changes->link($changes->token($user, $email)),
            ]),
        )));

        if (null !== $user->email) {
            $queue->push(SendMail::of(new Message(
                [$user->email],
                $translator->in($language, 'Your email for {site} is about to change', [], ['site' => $site]),
                $translator->in($language, "Hello {name},\n\nyour account on {site} is to sign in with {email} from now on. It changes once the link in the mail to that address is used.\n\nIf it was not you, change your password right away. That makes the link void.\n", [], [
                    'name' => $user->name,
                    'site' => $site,
                    'email' => $email,
                ]),
            )));
        }

        return new EmailChange($email, true);
    }
}
