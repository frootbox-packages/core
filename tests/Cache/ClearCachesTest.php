<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Cache;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Catalog;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Cache\CacheFolder;
use Frootbox\Core\Cache\CachesCleared;
use Frootbox\Core\Cache\ClearCaches;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * var/cache emptied from the backend, for webspace without a console.
 */
#[CoversClass(ClearCaches::class)]
#[CoversClass(CacheFolder::class)]
#[CoversClass(CachesCleared::class)]
final class ClearCachesTest extends NotesTestCase
{
    public function testEmptiesTheCacheFolder(): void
    {
        $cache = $this->get(ProjectPaths::class)->var('cache');

        foreach (['twig/ab/template.php', 'pages/de/index.html', 'extensions.cache'] as $file) {
            @mkdir(\dirname($cache . '/' . $file), 0o775, true);
            file_put_contents($cache . '/' . $file, 'cached');
        }

        $result = $this->runner()->run(new ClearCaches());

        self::assertGreaterThanOrEqual(3, $result->files);
        self::assertFileDoesNotExist($cache . '/twig/ab/template.php');
        self::assertFileDoesNotExist($cache . '/pages/de/index.html');
        self::assertSame(0, $this->runner()->run(new ClearCaches())->files, 'Nothing left.');
    }

    public function testOnlySuperusersAndAPersonConfirmsBeforeAnAi(): void
    {
        $descriptor = $this->get(Catalog::class)->get('system.cache-clear');
        self::assertFalse($descriptor->undoable);
        self::assertTrue($descriptor->confirm);

        $admin = $this->runner()->run(new CreateUser('jane@example.com', 'Jane', 'kaffeebohne42', ['admin']))->user;

        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new ClearCaches(), new Context(Actor::user($admin->id, $admin->name), Channel::Api));
    }
}
