<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Account\PasswordResets;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\ActorType;
use Frootbox\Core\Auth\Hierarchy;
use Frootbox\Core\Config\Config;
use Frootbox\Core\I18n\Translator;
use Frootbox\Core\Mail\Message;
use Frootbox\Core\Mail\SendMail;
use Frootbox\Core\Queue\Queue;
use Frootbox\Core\Support\Message as Text;

/**
 * Mails a person without a password a link to set one (decision 0342).
 * Again, too, while they have none.
 *
 * @implements Action<void>
 */
#[AsAction('user.invite', 'Mails a person without a password an invitation with a link to set one; works again until they have', undoable: false)]
final readonly class InviteUser implements Action
{
    public function __construct(public int $id) {}

    public function handle(Users $users, PasswordResets $links, Hierarchy $hierarchy, CurrentContext $context, Queue $queue, Translator $translator, Config $config): void
    {
        $hierarchy->assertMayManageUser($this->id);
        $user = $users->getById($this->id);

        if (!$links->available()) {
            throw new InvalidInputException('user.invite', ['' => 'This website sends no invitations: it needs APP_SECRET, app.url and a way for mails.']);
        }

        if (UserKind::Service === $user->kind || null === $user->email) {
            throw UserException::serviceAccount('invite');
        }

        if (!$user->active) {
            throw new InvalidInputException('user.invite', ['' => new Text('{name} is disabled.', ['name' => $user->name])]);
        }

        if (null !== $users->passwordHash($user->id)) {
            throw new InvalidInputException('user.invite', ['' => new Text('{name} has a password already and can ask for a new one at the sign-in.', ['name' => $user->name])]);
        }

        $language = $user->language ?? $translator->standard();
        $site = $config->string('app.name', 'Frootbox');
        $actor = $context->get()->actor;
        $inviter = ActorType::User === $actor->type && null !== $actor->id ? $users->fetchById($actor->id) : null;
        $queue->push(SendMail::of(new Message(
            [$user->email],
            $translator->in($language, 'Invitation to {site}', [], ['site' => $site]),
            $translator->in($language, "Hello {name},\n\n{inviter} made an account on {site} for you. Choose your password with this link within {days} days:\n\n{link}\n\nYou then sign in with {email}.\n", [], [
                'name' => $user->name,
                'inviter' => $inviter->name ?? $site,
                'site' => $site,
                'days' => PasswordResets::INVITATION_DAYS,
                'link' => $links->link($links->invitationToken($user), 'welcome'),
                'email' => $user->email,
            ]),
        )));
    }
}
