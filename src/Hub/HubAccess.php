<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

use Frootbox\Core\Action\Runner;
use Frootbox\Core\Api\ApiHandler;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateServiceToken;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\DeleteRole;
use Frootbox\Core\User\DeleteUser;
use Frootbox\Core\User\Roles;
use Frootbox\Core\User\UserKind;
use Frootbox\Core\User\Users;

/**
 * The access of the hub to the status (decisions 0284, 0285): a service
 * account whose role may only ask status.show, and its token. Runs inside
 * the actions of the hub, so each step is an action of its own in the log.
 */
final readonly class HubAccess
{
    public const string ROLE = 'hub-status';

    public function __construct(
        private Runner $runner,
        private Roles $roles,
        private Users $users,
    ) {}

    /**
     * Creates role, service account and token.
     *
     * @return array{userId: int, status: array{url: string, token: string}}
     */
    public function grant(string $hubUrl, string $siteUrl): array
    {
        if (null === $this->roles->findByName(self::ROLE)) {
            $this->runner->run(new CreateRole(self::ROLE, 'Status for the hub', ['status.show']));
        }

        $host = (string) parse_url($hubUrl, \PHP_URL_HOST);
        $user = $this->runner->run(new CreateUser(null, $host, roles: [self::ROLE], kind: UserKind::Service))->user;
        $token = $this->runner->run(new CreateServiceToken($user->id, 'Status for ' . $host))->token;

        return ['userId' => $user->id, 'status' => ['url' => $siteUrl . ApiHandler::PREFIX . '/status.show', 'token' => $token]];
    }

    /**
     * Deletes the service account with its token, and the role.
     */
    public function revoke(?int $userId): void
    {
        if (null !== $userId && null !== $this->users->fetchById($userId)) {
            $this->runner->run(new DeleteUser($userId));
        }

        if (null !== $this->roles->findByName(self::ROLE)) {
            $this->runner->run(new DeleteRole(self::ROLE));
        }
    }
}
