<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Frootbox\Core\Action\Journal;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\ConfigException;
use Frootbox\Core\Database\WriteGuard\WriteGuard;
use Frootbox\Core\ProjectPaths;

/**
 * Creates the database connection from database.url, e.g.
 *
 *   pdo-sqlite:///storage/database.sqlite   (path relative to the project root)
 *   pdo-mysql://user:secret@localhost:3306/frootbox
 *
 * The connection opens lazily on the first query. With a Journal, the
 * WriteGuard watches for writes that bypass the repositories. SQLite gets
 * the settings for a live website (SqliteSettings).
 */
final class ConnectionFactory
{
    public static function create(Config $config, ProjectPaths $paths, ?Journal $journal = null): Connection
    {
        $url = $config->string('database.url', '');

        if ('' === $url) {
            throw new ConfigException('No database configured. Set database.url in config/app.yaml, usually to %env(DATABASE_URL)%.');
        }

        $params = new DsnParser()->parse($url);
        $sqlite = \in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true);

        if ($sqlite && isset($params['path']) && !str_starts_with($params['path'], '/')) {
            $params['path'] = $paths->root . '/' . $params['path'];
        }

        $middlewares = [];

        if (null !== $journal) {
            $middlewares[] = new WriteGuard($journal);
        }

        if ($sqlite) {
            $middlewares[] = new SqliteSettings();
        }

        $configuration = new Configuration();
        $configuration->setMiddlewares($middlewares);

        return DriverManager::getConnection($params, $configuration);
    }
}
