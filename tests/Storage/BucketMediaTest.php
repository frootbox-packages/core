<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Storage;

use DateTimeImmutable;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Console\CacheClearCommand;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Media\MediaHandler;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\Medium;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\PublishMedia;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Core\Storage\FileStores;
use Frootbox\Core\Storage\StorageCache;
use Frootbox\Core\Storage\StorageDriver;
use Frootbox\Core\Storage\StoreRole;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\MemoryStore;
use Frootbox\Core\Tests\Support\MemoryStores;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\VideoFiles;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Media with both stores in a bucket (decision 0268): originals never in
 * storage/, sizes and copies never in public/, addresses of the bucket
 * in the page once a file is there.
 *
 * @internal
 */
#[CoversClass(FileStores::class)]
#[CoversClass(MediaFiles::class)]
#[CoversClass(MediaHandler::class)]
#[CoversClass(MediaUrls::class)]
#[CoversClass(StorageCache::class)]
final class BucketMediaTest extends NotesTestCase
{
    private const string BUCKET = 'https://bucket.example';

    public function testKeepsTheOriginalInTheBucketAndACopyOnDisk(): void
    {
        $medium = $this->image('hafen.jpg', 800, 600);

        self::assertArrayHasKey('media/' . $medium->id, $this->storage()->files);
        self::assertSame('image/jpeg', $this->storage()->files['media/' . $medium->id]['type']);
        self::assertFileDoesNotExist($this->get(ProjectPaths::class)->storage('media/' . $medium->id), 'Not in the folder.');
        self::assertFileExists($this->get(ProjectPaths::class)->var('storage/storage/media/' . $medium->id), 'The sizes come next, so the copy stays.');
    }

    public function testDeliversFromTheBucketOnceASizeIsThere(): void
    {
        $medium = $this->image('hafen.jpg', 1200, 800);
        $urls = $this->get(MediaUrls::class);
        $url = $urls->url($this->model($medium->id), 640, 0, 'jpg');
        self::assertStringStartsWith('/media/' . $medium->id . '/1/hafen-640x0-', $url);

        // Not public: only through PHP, never in the bucket.
        self::assertSame(404, $this->deliver($url)->getStatusCode());
        self::assertSame(200, $this->deliver($url, $this->token())->getStatusCode());
        self::assertSame([], $this->public()->files);

        $this->publish(['cover' => ['media' => $medium->id]]);
        $response = $this->deliver($url);
        $key = substr($url, 1);
        self::assertSame(200, $response->getStatusCode(), 'The first request generates it.');
        self::assertSame(\strlen((string) $response->getBody()), \strlen($this->public()->files[$key]['contents']));
        self::assertSame('image/jpeg', $this->public()->files[$key]['type']);
        self::assertFileDoesNotExist($this->get(ProjectPaths::class)->public($key), 'Nothing in public/.');

        self::assertSame(self::BUCKET . $url, $urls->url($this->model($medium->id), 640, 0, 'jpg'), 'The page gets the address of the bucket.');
        self::assertSame('/media/', substr($urls->url($this->model($medium->id), 320, 0, 'jpg'), 0, 7), 'A size not generated yet keeps the own address.');

        $again = $this->deliver($url);
        self::assertSame(302, $again->getStatusCode(), 'An old address in a cached page goes on to the bucket.');
        self::assertSame(self::BUCKET . $url, $again->getHeaderLine('Location'));
    }

    public function testFindsTheFileInTheBucketAgainWithoutTheMarks(): void
    {
        $medium = $this->image('hafen.jpg', 800, 600);
        $this->publish(['cover' => ['media' => $medium->id]]);
        $url = $this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg');
        $this->deliver($url);

        // cache:clear after a deployment keeps the marks, only var/ as a whole takes them.
        new CommandTester($this->get(CacheClearCommand::class))->execute([]);
        self::assertStringStartsWith(self::BUCKET, $this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg'));
        self::removeTree($this->get(ProjectPaths::class)->var('storage/marks'));
        self::assertStringStartsWith('/media/', $this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg'));
        $response = $this->deliver($url);

        self::assertSame(302, $response->getStatusCode(), 'The bucket has it, nothing is generated again.');
        self::assertSame(self::BUCKET . $url, $this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg'), 'The mark is back.');
    }

    public function testEmptiesThePageCacheOnceNewFilesAreInTheBucket(): void
    {
        $medium = $this->image('hafen.jpg', 800, 600);
        $this->publish(['cover' => ['media' => $medium->id]]);
        $generation = fn(): string => (string) @file_get_contents($this->get(ProjectPaths::class)->var('cache/pages/generation'));
        $schedule = $this->get(StorageCache::class);
        $this->get(PageCache::class)->clear();
        $before = $generation();

        $schedule(new ScheduleRan(new DateTimeImmutable()));
        self::assertSame($before, $generation(), 'Nothing new in the bucket.');

        $this->deliver($this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg'));
        $schedule(new ScheduleRan(new DateTimeImmutable()));
        $after = $generation();
        self::assertNotSame($before, $after, 'Pages are rendered again with the address of the bucket.');

        $schedule(new ScheduleRan(new DateTimeImmutable()));
        self::assertSame($after, $generation(), 'Once only.');
    }

    public function testANewVersionRemovesTheFilesOfTheOldOne(): void
    {
        $medium = $this->image('hafen.jpg', 800, 600);
        $this->publish(['cover' => ['media' => $medium->id]]);
        $url = $this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg');
        $this->deliver($url);
        self::assertNotSame([], $this->public()->files);

        $this->runner()->run(new UpdateMedium($medium->id, focusX: 0.2, focusY: 0.5));
        $this->runner()->run(new PublishMedia([$medium->id]));

        self::assertSame([], $this->public()->files);
        self::assertStringStartsWith('/media/' . $medium->id . '/2/', $this->get(MediaUrls::class)->url($this->model($medium->id), 640, 0, 'jpg'));
        self::assertSame(404, $this->deliver($url)->getStatusCode(), 'The old version is gone, no redirect either.');
    }

    public function testCopiesAVideoWithinTheStoresAndSendsTheBrowserThere(): void
    {
        $file = VideoFiles::webm($this->path('webm'), 640, 360, padding: str_repeat('v', 20_000));
        $video = $this->runner()->run(new CreateMedium($this->receive($file, 'Röstung.webm')['upload']));
        self::assertFileDoesNotExist($this->get(ProjectPaths::class)->var('storage/storage/media/' . $video->id), 'A video is not resized, the copy goes.');

        // Not public: PHP serves it from the bucket for data, with Range.
        $url = $this->get(MediaUrls::class)->video($this->model($video->id));
        $part = $this->deliver($url, $this->token(), 'bytes=0-99');
        self::assertSame(206, $part->getStatusCode());
        self::assertSame(100, \strlen((string) $part->getBody()));

        $this->publish(['clip' => ['media' => $video->id]]);
        $response = $this->deliver($url);

        self::assertSame(302, $response->getStatusCode(), 'The bucket answers Range itself.');
        self::assertSame(self::BUCKET . $url, $response->getHeaderLine('Location'));
        self::assertContains('copy media/' . $video->id, $this->public()->calls);
        self::assertSame($this->storage()->files['media/' . $video->id]['contents'], $this->public()->files[substr($url, 1)]['contents']);
    }

    public function testPrunesOriginalsInTheBucket(): void
    {
        $medium = $this->image('hafen.jpg', 400, 300);
        $this->storage()->files['media/' . $medium->id . '-0a1b2c3d'] = ['contents' => 'old', 'type' => 'image/jpeg', 'modified' => time() - 40 * 86400];

        $removed = $this->get(MediaFiles::class)->pruneOriginals(
            static fn(string $name): bool => $name === $medium->id,
            static fn(string $id): bool => $id === $medium->id,
            31 * 86400,
        );

        self::assertSame(1, $removed);
        self::assertSame(['media/' . $medium->id], array_keys($this->storage()->files));
    }

    public function testRemovesCopiesOnDiskNobodyUsedForADay(): void
    {
        $medium = $this->image('hafen.jpg', 400, 300);
        $copy = $this->get(ProjectPaths::class)->var('storage/storage/media/' . $medium->id);
        self::assertFileExists($copy);

        $this->get(StorageCache::class)(new ScheduleRan(new DateTimeImmutable()));
        self::assertFileExists($copy, 'Used just now.');

        touch($copy, time() - 2 * StorageCache::LIFETIME);
        self::assertSame(1, $this->get(StorageCache::class)->prune());
        self::assertFileDoesNotExist($copy);

        self::assertFileExists($this->get(MediaFiles::class)->original($medium->id), 'Fetched again when needed.');
        self::assertContains('fetch media/' . $medium->id, $this->storage()->calls);
    }

    public function testTheFolderOfTheInstallationStaysAvailable(): void
    {
        $stores = $this->get(FileStores::class);

        self::assertTrue($stores->isRemote(StoreRole::Storage));
        self::assertSame('storage/', $stores->local(StoreRole::Storage)->name());
        self::assertSame($this->get(ProjectPaths::class)->public('x'), $stores->local(StoreRole::Public)->localPath('x'));
    }

    protected function register(Registry $registry): void
    {
        $registry->add(StorageDriver::class, new StorageDriver('memory', MemoryStores::class));
    }

    protected function env(): array
    {
        return ['STORAGE_DSN' => 'memory://default', 'PUBLIC_STORAGE_DSN' => 'memory://default?url=' . self::BUCKET];
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'media');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * Media become public with a published page that uses them.
     *
     * @param array<string, mixed> $fields Of the gallery block
     */
    private function publish(array $fields): void
    {
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Medien'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => $fields],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$page]));
    }

    private function storage(): MemoryStore
    {
        $store = $this->get(FileStores::class)->storage();
        self::assertInstanceOf(MemoryStore::class, $store);

        return $store;
    }

    private function public(): MemoryStore
    {
        $store = $this->get(FileStores::class)->public();
        self::assertInstanceOf(MemoryStore::class, $store);

        return $store;
    }

    private function model(string $id): Medium
    {
        return $this->get(Media::class)->getById($id);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function image(string $name, int $width, int $height): MediumDetails
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, 3, 3, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->path('jpg');
        imagejpeg($image, $file, 90);

        return $this->runner()->run(new CreateMedium($this->receive($file, $name)['upload']));
    }

    private function path(string $extension): string
    {
        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.' . $extension);
        @mkdir(\dirname($file), 0o775, true);

        return $file;
    }

    /**
     * @return array{upload: string, name: string, size: int, complete: true}|array{upload: string, received: int, complete: false}
     */
    private function receive(string $file, string $name): array
    {
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, (int) filesize($file), \UPLOAD_ERR_OK, $name)]);

        return $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor);
    }

    private function token(): string
    {
        $user = $this->runner()->run(new CreateUser('bucket' . bin2hex(random_bytes(3)) . '@example.com', 'User', roles: ['editor']), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function deliver(string $path, ?string $token = null, ?string $range = null): ResponseInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org' . $path);
        $request = null === $token ? $request : $request->withHeader('Authorization', 'Bearer ' . $token);
        $request = null === $range ? $request : $request->withHeader('Range', $range);
        $this->get(CurrentContext::class)->set(Context::guest());

        try {
            return new MiddlewareDispatcher(array_map($this->get(...), Application::MIDDLEWARE), $this->get(NotFoundHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system());
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        foreach (is_dir($path) ? (array) scandir($path) : [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::removeTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
