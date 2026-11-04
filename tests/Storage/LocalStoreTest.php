<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Storage;

use Frootbox\Core\Config\Config;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Storage\Dsn;
use Frootbox\Core\Storage\FileInfo;
use Frootbox\Core\Storage\FileStores;
use Frootbox\Core\Storage\LocalStore;
use Frootbox\Core\Storage\StorageException;
use Frootbox\Core\Storage\StorePath;
use Frootbox\Core\Storage\StoreRole;
use Frootbox\Core\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @internal
 */
#[CoversClass(LocalStore::class)]
#[CoversClass(StorePath::class)]
#[CoversClass(Dsn::class)]
#[CoversClass(FileStores::class)]
final class LocalStoreTest extends TestCase
{
    private TemporaryProject $project;

    protected function setUp(): void
    {
        $this->project = TemporaryProject::create();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testWritesReadsListsAndDeletes(): void
    {
        $store = new LocalStore($this->project->root . '/storage', 'storage/');
        $store->write('media/a/1.txt', 'eins');
        $store->write('media/a/2.txt', 'zwei');
        $store->write('media/b.txt', 'drei');

        self::assertSame('eins', $store->read('media/a/1.txt'));
        self::assertSame('wei', (string) $store->readRange('media/a/2.txt', 1));
        self::assertSame('dr', (string) $store->readRange('media/b.txt', 0, 2));
        self::assertSame(4, $store->info('media/a/2.txt')?->size);
        self::assertNull($store->read('media/c.txt'));
        self::assertNull($store->url('media/b.txt'), 'The own address serves a folder.');

        $paths = array_map(static fn(FileInfo $file): string => $file->path, [...$store->list('media/')]);
        sort($paths);
        self::assertSame(['media/a/1.txt', 'media/a/2.txt', 'media/b.txt'], $paths);

        $store->deletePrefix('media/a/');
        self::assertFalse($store->exists('media/a/1.txt'));
        self::assertDirectoryDoesNotExist($this->project->root . '/storage/media/a');
        self::assertTrue($store->exists('media/b.txt'));
    }

    public function testStagingIsTheFileItself(): void
    {
        $store = new LocalStore($this->project->root . '/storage');
        $file = $store->stage('media/neu');
        file_put_contents($file, 'x');
        $store->commit('media/neu');

        self::assertSame($store->localPath('media/neu'), $file);
        self::assertSame('x', $store->read('media/neu'));
    }

    public function testRefusesPathsOutOfTheStore(): void
    {
        $this->expectException(StorageException::class);

        new LocalStore($this->project->root . '/storage')->read('media/../../.env');
    }

    public function testReadsADsn(): void
    {
        $dsn = Dsn::parse('s3://AKIA%2Fx:geh%2Beim@fsn1.your-objectstorage.com/kunde-daten?region=fsn1&path_style=1');

        self::assertSame(['s3', 'fsn1.your-objectstorage.com', 'kunde-daten'], [$dsn->scheme, $dsn->host, $dsn->path]);
        self::assertSame(['AKIA/x', 'geh+eim'], [$dsn->user, $dsn->password]);
        self::assertSame('fsn1', $dsn->option('region'));
        self::assertTrue($dsn->flag('path_style'));
        self::assertSame('s3://fsn1.your-objectstorage.com/kunde-daten', $dsn->safe(), 'Without the secret.');
    }

    public function testNamesThePackageForAnUnknownKindOfStore(): void
    {
        $config = new Config(Environment::Development, [], ['STORAGE_DSN' => 's3://k:s@host/bucket']);
        $stores = new FileStores($config, new ProjectPaths($this->project->root), new Registry(), self::createStub(ContainerInterface::class));

        self::assertFalse($stores->isRemote(StoreRole::Public));
        self::assertInstanceOf(LocalStore::class, $stores->public());
        $this->expectExceptionMessage('composer require frootbox/storage-s3');
        $stores->storage();
    }
}
