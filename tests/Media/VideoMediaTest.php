<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Types\VideoType;
use Frootbox\Core\Http\FileRangeStream;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Media\AddMediaSource;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\DeleteMediaSource;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\Media;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Media\MediaHandler;
use Frootbox\Core\Media\MediaSources;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\ReplaceMedium;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadHandler;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Media\Uploads;
use Frootbox\Core\Media\Videos;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Tests\Support\VideoFiles;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Videos in the media library, uploads in parts and the field type video
 * (decision 0177).
 */
#[CoversClass(Videos::class)]
#[CoversClass(Uploads::class)]
#[CoversClass(UploadReceiver::class)]
#[CoversClass(MediaHandler::class)]
#[CoversClass(MediaSources::class)]
#[CoversClass(AddMediaSource::class)]
#[CoversClass(DeleteMediaSource::class)]
#[CoversClass(VideoType::class)]
#[CoversClass(FileRangeStream::class)]
final class VideoMediaTest extends NotesTestCase
{
    private int $users = 0;

    public function testUploadsInPartsAndGoesOnAfterAnInterruption(): void
    {
        $file = VideoFiles::webm($this->path('webm'), 640, 360, padding: str_repeat("\x42", 250_000));
        $bytes = (string) file_get_contents($file);
        $total = \strlen($bytes);
        $parts = str_split($bytes, 100_000);

        $first = $this->part($parts[0], 'Röstung.webm', $total, 0);
        self::assertFalse($first['complete']);
        self::assertSame(100_000, $first['received']);
        $token = $first['upload'];
        self::assertNull($this->get(Uploads::class)->path($token), 'Not usable before the last part.');

        // The third part too early: the server says where it stands.
        try {
            $this->part($parts[2], 'Röstung.webm', $total, 200_000, $token);
            self::fail('Expected a conflict.');
        } catch (ConflictException $error) {
            self::assertSame(100_000, $error->received);
        }

        $second = $this->part($parts[1], 'Röstung.webm', $total, 100_000, $token);
        self::assertFalse($second['complete']);
        self::assertSame(200_000, $second['received']);
        $last = $this->part($parts[2], 'Röstung.webm', $total, 200_000, $token);
        self::assertTrue($last['complete']);
        self::assertSame('Röstung.webm', $last['name']);
        self::assertSame($total, $last['size']);

        $medium = $this->runner()->run(new CreateMedium($token));
        self::assertSame('video', $medium->kind);
        self::assertSame('video/webm', $medium->type);
        self::assertSame('vp9', $medium->codec);
        self::assertSame([640, 360], [$medium->width, $medium->height]);
        self::assertSame(hash('sha256', $bytes), $this->get(Media::class)->getById($medium->id)->hash);
        self::assertMatchesRegularExpression('#^/media/' . $medium->id . '/1/roestung-main-[0-9a-f]{16}\.webm$#', $medium->preview);

        // The first part tells the kind; others than images and videos stop there.
        $this->assertInvalid(fn() => $this->part('<?php echo 1;', 'x.php', 1000, 0), '/file');
        $this->assertInvalid(fn() => $this->part($parts[1], 'Röstung.webm', $total, 100_000, 'f' . str_repeat('0', 31)), '/upload');
        $this->assertInvalid(fn() => $this->part($parts[0], 'Röstung.webm', $total, 5), '/offset');
    }

    public function testAnswersAPartTooEarlyWithTheOffsetThroughTheApi(): void
    {
        $bytes = (string) file_get_contents(VideoFiles::webm($this->path('webm'), 320, 240, padding: str_repeat('x', 5000)));
        $token = $this->apiPart(substr($bytes, 0, 1000), \strlen($bytes), 0, null);
        self::assertSame(201, $token->getStatusCode(), (string) $token->getBody());
        $data = json_decode((string) $token->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['data']);
        self::assertIsString($data['data']['upload']);

        $conflict = $this->apiPart(substr($bytes, 2000, 1000), \strlen($bytes), 2000, $data['data']['upload']);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('1000', $conflict->getHeaderLine('Upload-Offset'));
    }

    public function testAVideoHasFilesInSeveralCodecsWithTheMostEfficientFirst(): void
    {
        $video = $this->video(VideoFiles::mp4($this->path('mp4'), 1920, 1080, padding: 'h264'), 'Röstung.mp4', ['de' => ['alt' => 'Kaffeebohnen in der Trommel', 'title' => '']]);
        self::assertSame('avc1.64001F', $video->codec);

        $video = $this->addSource($video->id, VideoFiles::webm($this->path('webm'), 1920, 1080, padding: 'vp9'));
        $video = $this->addSource($video->id, VideoFiles::mp4($this->path('mp4'), 1920, 1080, 'hvc1', 'hvcC', VideoFiles::HEVC, 'hevc'));
        self::assertSame(['MP4 (hvc1)', 'WebM (vp9)'], array_column($video->sources, 'format'));

        // One file per container and codec, no image, not the same file twice.
        $this->assertInvalid(fn() => $this->addSource($video->id, VideoFiles::mp4($this->path('mp4'), 1280, 720, padding: 'other')), '/upload');
        $this->assertInvalid(fn() => $this->addSource($video->id, $this->image()), '/upload');
        $image = $this->runner()->run(new CreateMedium($this->receive($this->image(), 'bild.jpg')['upload']));
        $this->assertInvalid(fn() => $this->addSource($image->id, VideoFiles::webm($this->path('webm'), 10, 10, padding: 'x')), '/mediaId');

        $poster = $this->runner()->run(new CreateMedium($this->receive($this->image(1600, 900), 'standbild.jpg')['upload']));
        $html = $this->publishWith(['media' => $video->id, 'poster' => $poster->id]);

        self::assertMatchesRegularExpression('#<video controls playsinline preload="none" width="1920" height="1080" poster="/media/' . $poster->id . '/1/standbild-1280x0-[0-9a-f]{16}\.(webp|jpg)" aria-label="Kaffeebohnen in der Trommel">#', $html);
        preg_match_all('#<source src="([^"]+)" type="([^"]+)">#', $html, $sources);
        self::assertSame(['video/mp4; codecs=&quot;hvc1.1.6.L93.B0&quot;', 'video/webm; codecs=&quot;vp9&quot;', 'video/mp4; codecs=&quot;avc1.64001F&quot;'], $sources[2]);
        self::assertStringEndsWith('.mp4', $sources[1][2]);
        self::assertStringContainsString('-main-', $sources[1][2]);

        $this->runner()->run(new DeleteMediaSource($video->id, $video->sources[0]['id']));
        self::assertCount(1, $this->get(MediaSources::class)->of($video->id));

        self::assertSame([$video->id], array_map(static fn(MediumDetails $item): string => $item->id, $this->runner()->ask(new ListMedia(kind: 'video'))->items));
        self::assertNotContains($video->id, array_map(static fn(MediumDetails $item): string => $item->id, $this->runner()->ask(new ListMedia(kind: 'image'))->items));
    }

    public function testDeliversVideosWithRangesAndCopiesPublicOnes(): void
    {
        $file = VideoFiles::webm($this->path('webm'), 640, 360, padding: str_repeat('0123456789', 100));
        $bytes = (string) file_get_contents($file);
        $video = $this->video($file, 'film.webm');
        $url = $video->preview;

        // Not public yet: only for those who may see the media library.
        self::assertSame(404, $this->deliver($url)->getStatusCode());
        $editor = $this->token(['editor']);
        $whole = $this->deliver($url, $editor);
        self::assertSame(200, $whole->getStatusCode());
        self::assertSame('video/webm', $whole->getHeaderLine('Content-Type'));
        self::assertSame('bytes', $whole->getHeaderLine('Accept-Ranges'));
        self::assertSame('private, no-store', $whole->getHeaderLine('Cache-Control'));
        self::assertSame($bytes, (string) $whole->getBody());

        $part = $this->deliver($url, $editor, 'bytes=10-19');
        self::assertSame(206, $part->getStatusCode());
        self::assertSame(\sprintf('bytes 10-19/%d', \strlen($bytes)), $part->getHeaderLine('Content-Range'));
        self::assertSame('10', $part->getHeaderLine('Content-Length'));
        self::assertSame(substr($bytes, 10, 10), (string) $part->getBody());
        self::assertSame(substr($bytes, -5), (string) $this->deliver($url, $editor, 'bytes=-5')->getBody());
        self::assertSame(substr($bytes, 100), (string) $this->deliver($url, $editor, 'bytes=100-')->getBody());
        self::assertSame(416, $this->deliver($url, $editor, 'bytes=999999-')->getStatusCode());
        self::assertFileDoesNotExist($this->get(MediaFiles::class)->public(substr($url, \strlen(MediaUrls::PREFIX))));

        $this->publishWith(['media' => $video->id]);
        $public = $this->deliver($url, null, 'bytes=0-3');
        self::assertSame(206, $public->getStatusCode());
        self::assertStringContainsString('immutable', $public->getHeaderLine('Cache-Control'));
        self::assertFileEquals($file, $this->get(MediaFiles::class)->public(substr($url, \strlen(MediaUrls::PREFIX))));

        // Only signed addresses and files of this video.
        self::assertSame(404, $this->deliver(preg_replace('/-[0-9a-f]{16}\./', '-0000000000000000.', $url) ?? '')->getStatusCode());
        self::assertSame(404, $this->deliver(str_replace('.webm', '.mp4', $url))->getStatusCode());
        self::assertSame(404, $this->deliver(str_replace('-main-', '-' . strtolower($video->id) . '-', $url))->getStatusCode());
    }

    public function testAVideoHasNoFocusPointAndIsReplacedOnlyByAVideo(): void
    {
        $video = $this->video(VideoFiles::webm($this->path('webm'), 640, 360, padding: 'a'), 'film.webm');
        $image = $this->runner()->run(new CreateMedium($this->receive($this->image(), 'bild.jpg')['upload']));

        $this->assertInvalid(fn() => $this->runner()->run(new UpdateMedium($video->id, focusX: 0.2, focusY: 0.2)), '/focusX');
        $this->assertInvalid(fn() => $this->runner()->run(new ReplaceMedium($video->id, $this->receive($this->image(), 'bild.jpg')['upload'])), '/upload');
        $this->assertInvalid(fn() => $this->runner()->run(new ReplaceMedium($image->id, $this->receive(VideoFiles::webm($this->path('webm'), 640, 360, padding: 'b'), 'film.webm')['upload'])), '/upload');

        $replaced = $this->runner()->run(new ReplaceMedium($video->id, $this->receive(VideoFiles::mp4($this->path('mp4'), 1280, 720, padding: 'c'), 'neu.mp4')['upload']));
        self::assertSame(['video/mp4', 'avc1.64001F', 1280], [$replaced->type, $replaced->codec, $replaced->width]);

        // A video in an image field and an image in a video field show nothing.
        $html = $this->publishWith(['media' => $image->id], ['media' => $video->id]);
        self::assertStringNotContainsString('<video', $html);
        self::assertStringNotContainsString('<picture', $html);
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
     * Publishes a page with the video field and gives its HTML for guests.
     *
     * @param array<string, string>      $clip
     * @param array<string, string>|null $cover
     */
    private function publishWith(array $clip, ?array $cover = null): string
    {
        $fields = ['clip' => $clip] + (null === $cover ? [] : ['cover' => $cover]);
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Film'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => $fields],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$page]));
        $this->get(Urls::class)->clear();

        return (string) $this->get(PageHandler::class)->handle(new Psr17Factory()->createServerRequest('GET', 'https://example.org/'))->getBody();
    }

    /**
     * @param array<mixed> $texts
     */
    private function video(string $file, string $name, array $texts = []): MediumDetails
    {
        return $this->runner()->run(new CreateMedium($this->receive($file, $name)['upload'], texts: $texts));
    }

    private function addSource(string $id, string $file): MediumDetails
    {
        return $this->runner()->run(new AddMediaSource($id, $this->receive($file, basename($file))['upload']));
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

    /**
     * @return array{upload: string, name: string, size: int, complete: true}|array{upload: string, received: int, complete: false}
     */
    private function part(string $bytes, string $name, int $total, int $offset, ?string $token = null): array
    {
        $file = $this->path('part');
        file_put_contents($file, $bytes);
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, \strlen($bytes), \UPLOAD_ERR_OK, 'blob')])
            ->withParsedBody(['name' => $name, 'total' => (string) $total, 'offset' => (string) $offset] + (null === $token ? [] : ['upload' => $token]));

        return $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor);
    }

    private function apiPart(string $bytes, int $total, int $offset, ?string $token): ResponseInterface
    {
        $file = $this->path('part');
        file_put_contents($file, $bytes);
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost' . UploadHandler::PATH)
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, \strlen($bytes), \UPLOAD_ERR_OK, 'blob')])
            ->withParsedBody(['name' => 'film.webm', 'total' => (string) $total, 'offset' => (string) $offset] + (null === $token ? [] : ['upload' => $token]))
            ->withHeader('Authorization', 'Bearer ' . $this->token(['editor']));

        return $this->send($request);
    }

    private function path(string $extension): string
    {
        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.' . $extension);
        @mkdir(\dirname($file), 0o775, true);

        return $file;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function image(int $width = 40, int $height = 30): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, 3, 3, (int) imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $file = $this->path('jpg');
        imagejpeg($image, $file, 90);

        return $file;
    }

    /**
     * @param list<string> $roles
     */
    private function token(array $roles): string
    {
        $user = $this->runner()->run(new CreateUser('video' . ++$this->users . '@example.com', 'User', roles: $roles), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function deliver(string $path, ?string $token = null, ?string $range = null): ResponseInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org' . $path);
        $request = null === $token ? $request : $request->withHeader('Authorization', 'Bearer ' . $token);

        return $this->send(null === $range ? $request : $request->withHeader('Range', $range));
    }

    private function send(ServerRequestInterface $request): ResponseInterface
    {
        // Each request starts as a guest, like in production.
        $this->get(CurrentContext::class)->set(Context::guest());
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        try {
            return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
        } finally {
            $this->get(CurrentContext::class)->set(Context::system());
        }
    }

    private function assertInvalid(callable $callback, string $pointer): void
    {
        try {
            $callback();
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey($pointer, $error->messages, implode(', ', array_keys($error->messages)));

            return;
        }

        self::fail('Expected invalid input at ' . $pointer);
    }
}
