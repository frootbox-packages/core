<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Account;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Bookkeeping;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\Email;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Auth\SignInThrottle;
use Frootbox\Core\Config\Config;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Mail\Message;
use Frootbox\Core\Mail\SendMail;
use Frootbox\Core\Queue\Queue;
use Frootbox\Core\User\Users;

/**
 * Mails a link for a new password (decision 0326). The answer is the same
 * whether the account exists or not, so nobody can try out addresses.
 *
 * @implements Action<void>
 */
#[AsAction('account.request-password-reset', 'Mails a link for a new password to the email of an account; answers the same for unknown addresses', public: true, undoable: false)]
final readonly class RequestPasswordReset implements Action
{
    public function __construct(
        #[Email]
        #[Length(max: 255)]
        public string $email,
    ) {}

    public function handle(Users $users, PasswordResets $resets, SignInThrottle $throttle, Bookkeeping $bookkeeping, CurrentContext $context, Queue $queue, Translator $translator, Config $config): void
    {
        if (!$resets->available()) {
            throw new InvalidInputException('account.request-password-reset', ['' => 'This website sends no links for a forgotten password.']);
        }

        $address = $context->get()->address;
        $throttle->checkReset($address);
        $allowed = $throttle->resetMailAllowed($this->email);
        // Every request counts, for unknown addresses too.
        $bookkeeping->afterwards(fn() => $throttle->countReset($this->email, $address));

        $user = $users->findByEmail($this->email);

        if (!$allowed || null === $user || null === $user->email || !$resets->accepts($user)) {
            return;
        }

        $language = $user->language ?? $translator->standard();
        $site = $config->string('app.name', 'Frootbox');
        $queue->push(SendMail::of(new Message(
            [$user->email],
            $translator->in($language, 'New password for {site}', [], ['site' => $site]),
            $translator->in($language, "Hello {name},\n\nsomeone asked for a new password for your account on {site}. Set it with this link within {minutes} minutes:\n\n{link}\n\nIf it was not you, ignore this mail. Your password stays as it is.\n", [], [
                'name' => $user->name,
                'site' => $site,
                'minutes' => PasswordResets::MINUTES,
                'link' => $resets->link($resets->token($user)),
            ]),
        )));
    }
}
