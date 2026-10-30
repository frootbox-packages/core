<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use DI\Container;
use Doctrine\DBAL\Connection;
use Frootbox\Core\Action\ActionLog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\LogEntry;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Container\ContainerFactory;
use Frootbox\Core\Database\MigrationsFactory;
use Frootbox\Core\Database\Migrator;
use Frootbox\Core\Extension\ExtensionLoader;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Fixtures\Notes\Notes;
use Frootbox\Core\Tests\Fixtures\Notes\NotesExtension;
use Frootbox\Core\Tests\Fixtures\Notes\Outbox;
use PHPUnit\Framework\TestCase;

/**
 * An installation with the notes test extension, migrated, acting as the
 * system. Runs against DATABASE_URL like the other database tests.
 */
abstract class NotesTestCase extends TestCase
{
    private const array TABLES = ['notes', ActionLog::CHANGES, ActionLog::ENTRIES, MigrationsFactory::TABLE];

    protected Container $container;

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $registry = ExtensionLoader::boot(new Extensions());
        new NotesExtension()->register($registry);

        $config = new Config(Environment::Development, ['database' => ['url' => TestDatabase::url()]]);
        $this->container = ContainerFactory::create($config, new ProjectPaths($this->project->root), new Extensions(), $registry);
        $this->get(CurrentContext::class)->set(Context::system(Channel::Console));

        TestDatabase::dropTables($this->connection(), ...self::TABLES);
        $this->get(Migrator::class)->migrate();
        Outbox::$messages = [];
    }

    protected function tearDown(): void
    {
        TestDatabase::dropTables($this->connection(), ...self::TABLES);
        $this->connection()->close();
        $this->project->remove();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function get(string $class): object
    {
        $service = $this->container->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    protected function runner(): Runner
    {
        return $this->get(Runner::class);
    }

    protected function notes(): Notes
    {
        return $this->get(Notes::class);
    }

    protected function connection(): Connection
    {
        return $this->get(Connection::class);
    }

    protected function lastEntry(): LogEntry
    {
        $entry = $this->get(ActionLog::class)->recent(1)[0] ?? null;
        self::assertNotNull($entry, 'The action log is empty.');

        return $entry;
    }
}
