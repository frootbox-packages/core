<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\User;

use Frootbox\Core\Config\Config;
use Frootbox\Core\I18n\UserLanguages;
use Psr\Container\ContainerInterface;

/**
 * The language of the backend as users.language holds it (decision 0161).
 * Without a database, e.g. before the installation, nobody chose one.
 */
final readonly class StoredUserLanguages implements UserLanguages
{
    public function __construct(
        private Config $config,
        private ContainerInterface $container,
    ) {}

    public function of(int $userId): ?string
    {
        $database = $this->config->get('database.url');

        if (!\is_string($database) || '' === $database) {
            return null;
        }

        $users = $this->container->get(Users::class);

        return $users instanceof Users ? $users->fetchById($userId)?->language : null;
    }
}
