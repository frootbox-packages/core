<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\ProjectPaths;

/**
 * Creates the database connection from database.url, e.g.
 *
 *   pdo-sqlite:///storage/database.sqlite   (path relative to the project root)
 *   pdo-mysql://user:secret@localhost:3306/frootbox
 *
 * The connection opens lazily on the first query.
 */
final class ConnectionFactory
{
    public static function create(Config $config, ProjectPaths $paths): Connection
    {
        $url = $config->string('database.url', '');

        if ('' === $url) {
            throw new ConfigException('No database configured. Set database.url in config/app.yaml, usually to %env(DATABASE_URL)%.');
        }

        $params = new DsnParser()->parse($url);

        if (isset($params['path']) && \in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true) && !str_starts_with($params['path'], '/')) {
            $params['path'] = $paths->root . '/' . $params['path'];
        }

        return DriverManager::getConnection($params);
    }
}
