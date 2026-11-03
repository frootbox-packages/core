<?php

/*
 * SPDX-License-Identifier: MIT
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
use Frootbox\Core\Page\Pages;
use Frootbox\Core\Page\PageTranslations;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Tests\Fixtures\Notes\Notes;
use Frootbox\Core\Tests\Fixtures\Notes\NotesExtension;
use Frootbox\Core\Tests\Fixtures\Notes\NoteSource;
use Frootbox\Core\Tests\Fixtures\Notes\NotesSettings;
use Frootbox\Core\Tests\Fixtures\Notes\Outbox;
use Frootbox\Core\Tests\Fixtures\Notes\SendNote;
use Frootbox\Core\Tests\Fixtures\Shop\ListOrders;
use PHPUnit\Framework\TestCase;

/**
 * An installation with the notes test extension, migrated, acting as the
 * system. Runs against DATABASE_URL like the other database tests.
 */
abstract class NotesTestCase extends TestCase
{
    private const array TABLES = ['object_fields', 'tag_links', 'tags', 'category_links', 'categories', 'category_groups', 'space_defaults', 'spaces', 'jobs', 'settings', 'search_index', 'comments', 'preview_links', 'site_protection', 'media_usages', 'media_sources', 'media', 'media_texts', 'media_tags', 'media_folders', 'notes', 'sign_in_failures', 'navigations', 'urls', 'redirects', 'not_found', Revisions::TABLE, PageTranslations::TABLE, Pages::TABLE, 'access_tokens', 'role_assignments', 'roles', 'users', ActionLog::CHANGES, ActionLog::ENTRIES, MigrationsFactory::TABLE];

    /** APP_SECRET of the tests, e.g. for signed media addresses. */
    protected const string SECRET = 'test-secret-0123456789abcdef';

    protected Container $container;

    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
        $extensions = $this->extensions();
        $registry = ExtensionLoader::boot($extensions);
        new NotesExtension()->register($registry);
        $registry->forPackage('acme/shop', static fn() => $registry->query(ListOrders::class));
        $registry->forPackage('acme/notes', static function () use ($registry): void {
            $registry->searchSource(NoteSource::class);
            $registry->settings(NotesSettings::class);
            $registry->job(SendNote::class);
        });

        $config = new Config($this->environment(), ['database' => ['url' => TestDatabase::url()], ...$this->config()], ['APP_SECRET' => self::SECRET, ...$this->env()]);
        $this->container = ContainerFactory::create($config, new ProjectPaths($this->project->root), $extensions, $registry);
        $this->get(CurrentContext::class)->set(Context::system(Channel::Console));

        TestDatabase::dropTables($this->connection(), ...$this->tables(), ...self::TABLES);
        $this->get(Migrator::class)->migrate();
        Outbox::$messages = [];
    }

    /**
     * Tables of further extensions to drop before and after each test,
     * e.g. those of a module.
     *
     * @return list<string>
     */
    protected function tables(): array
    {
        return [];
    }

    /**
     * Extensions besides the notes, e.g. with blocks and layouts.
     */
    protected function extensions(): Extensions
    {
        return new Extensions();
    }

    /**
     * Development by default: without caches.
     */
    protected function environment(): Environment
    {
        return Environment::Development;
    }

    /**
     * More variables of .env, e.g. SITE_PROTECT.
     *
     * @return array<string, string>
     */
    protected function env(): array
    {
        return [];
    }

    /**
     * More configuration, e.g. languages or the theme.
     *
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        TestDatabase::dropTables($this->connection(), ...$this->tables(), ...self::TABLES);
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

    /**
     * Same values and types as JSON, whatever the order of keys in objects.
     * MySQL stores JSON columns in its own key order, SQLite and MariaDB
     * keep the order they got.
     */
    protected static function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame(self::sortedKeys($expected), self::sortedKeys($actual), $message);
    }

    private static function sortedKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortedKeys(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    protected function lastEntry(): LogEntry
    {
        $entry = $this->get(ActionLog::class)->recent(1)[0] ?? null;
        self::assertNotNull($entry, 'The action log is empty.');

        return $entry;
    }
}
