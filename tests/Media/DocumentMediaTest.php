<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\TokenType;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Field\Types\FileType;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Media\CreateMedium;
use Frootbox\Core\Media\Documents;
use Frootbox\Core\Media\ListMedia;
use Frootbox\Core\Media\MediaFiles;
use Frootbox\Core\Media\MediaHandler;
use Frootbox\Core\Media\MediaUrls;
use Frootbox\Core\Media\MediumDetails;
use Frootbox\Core\Media\ReplaceMedium;
use Frootbox\Core\Media\UpdateMedium;
use Frootbox\Core\Media\UploadReceiver;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Documents in the media library and the field type file (decision 0179).
 */
#[CoversClass(Documents::class)]
#[CoversClass(FileType::class)]
#[CoversClass(MediaHandler::class)]
#[CoversClass(UploadReceiver::class)]
final class DocumentMediaTest extends NotesTestCase
{
    private int $users = 0;

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function files(): iterable
    {
        $open = self::zip(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office:document-content/>']);
        $office = self::zip(['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);

        yield 'PDF' => ["%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF", 'preise.pdf', 'application/pdf'];
        yield 'PDF under another name' => ["%PDF-1.4\n%%EOF", 'preise.bin', 'application/pdf'];
        yield 'Word' => [$office, 'brief.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        yield 'Excel' => [$office, 'liste.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield 'ZIP' => [$office, 'paket.zip', 'application/zip'];
        yield 'OpenDocument text' => [$open, 'brief.odt', 'application/vnd.oasis.opendocument.text'];
        yield 'OpenDocument under the wrong extension' => [$open, 'brief.ods', null];
        yield 'text' => ["Kaffee, Bohnen und Röstung\n", 'notiz.txt', 'text/plain'];
        yield 'CSV' => ["Sorte;Preis\nHochland;8,90\n", 'preise.csv', 'text/csv'];
        yield 'text cut in a character' => [str_repeat('a', 65535) . 'ö', 'lang.txt', 'text/plain'];
        yield 'text in Latin-1' => ["R\xF6stung", 'alt.txt', null];
        yield 'text with control characters' => ["MZ\x90\x00\x03", 'programm.txt', null];
        yield 'HTML' => ['<!doctype html><script>alert(1)</script>', 'seite.html', null];
        yield 'HTML as text' => ['<p>Kaffee</p>', 'seite.htm', null];
        yield 'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'bild.svg', null];
        yield 'program' => ["MZ\x90\x00\x03\x00\x00\x00", 'setup.exe', null];
        yield 'ZIP as a program' => [$office, 'setup.exe', null];
        yield 'empty text' => ['', 'leer.txt', null];
    }

    #[DataProvider('files')]
    public function testTellsDocumentsByTheirContent(string $bytes, string $name, ?string $type): void
    {
        $file = $this->path('bin');
        file_put_contents($file, $bytes);

        self::assertSame($type, Documents::type($file, $name));
    }

    public function testUploadsADocumentAndLinksItWithFormatAndSize(): void
    {
        $bytes = "%PDF-1.7\n" . str_repeat('0', 1_300_000) . "\n%%EOF";
        $document = $this->document($bytes, 'Preisliste 2027.pdf', ['de' => ['alt' => '', 'title' => 'Unsere Preise']]);

        self::assertSame('document', $document->kind);
        self::assertSame('application/pdf', $document->type);
        self::assertSame('PDF', $document->format);
        self::assertMatchesRegularExpression('#^/media/' . $document->id . '/1/preisliste-2027-main-[0-9a-f]{16}\.pdf$#', $document->preview);
        self::assertSame([$document->id], array_map(static fn(MediumDetails $item): string => $item->id, $this->runner()->ask(new ListMedia(kind: 'document'))->items));
        self::assertSame([], $this->runner()->ask(new ListMedia(kind: 'image'))->items);

        $html = $this->publishWith(['media' => $document->id]);
        self::assertStringContainsString('<a href="' . $document->preview . '" type="application/pdf">Unsere Preise <small>PDF, 1,2 MB</small></a>', $html);
    }

    public function testNamesTheFileWithItsOwnText(): void
    {
        $document = $this->document("%PDF-1.7\n%%EOF", 'preise.pdf', ['de' => ['alt' => '', 'title' => 'Unsere Preise']]);

        self::assertStringContainsString('>Preise &lt;2027&gt; <small>PDF, 1 KB</small></a>', $this->publishWith(['media' => $document->id, 'text' => 'Preise <2027>']));
    }

    public function testNamesTheFileWithoutTitle(): void
    {
        $document = $this->document("Sorte;Preis\n", 'preise.csv');

        self::assertStringContainsString('type="text/csv">preise <small>CSV, 1 KB</small></a>', $this->publishWith(['media' => $document->id]));
    }

    public function testFormatsTheSizeInTheLanguageOfThePage(): void
    {
        self::assertSame('1 KB', FileType::size(10, 'de'));
        self::assertSame('820 KB', FileType::size(820 * 1024, 'de'));
        self::assertSame('1,5 MB', FileType::size(1_572_864, 'de'));
        self::assertSame('1.5 MB', FileType::size(1_572_864, 'en'));
        self::assertSame('12 MB', FileType::size(12 * 1024 * 1024, 'en'));
    }

    public function testDeliversDocumentsAsTheyAreAndCopiesPublicOnes(): void
    {
        $bytes = "Sorte;Preis\nHochland;8,90\n";
        $document = $this->document($bytes, 'preise.csv');
        $url = $document->preview;

        self::assertSame(404, $this->deliver($url)->getStatusCode(), 'Not public yet.');
        $editor = $this->deliver($url, $this->token(['editor']));
        self::assertSame(200, $editor->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $editor->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $editor->getHeaderLine('X-Content-Type-Options'));
        self::assertSame($bytes, (string) $editor->getBody());

        $this->publishWith(['media' => $document->id]);
        self::assertSame(200, $this->deliver($url)->getStatusCode());
        self::assertStringEqualsFile($this->get(MediaFiles::class)->public(substr($url, \strlen(MediaUrls::PREFIX))), $bytes);

        self::assertSame(404, $this->deliver(preg_replace('/-[0-9a-f]{16}\./', '-0000000000000000.', $url) ?? '')->getStatusCode());
        self::assertSame(404, $this->deliver(str_replace('.csv', '.pdf', $url))->getStatusCode());
        self::assertSame(404, $this->deliver(str_replace('-main-', '-poster-', $url))->getStatusCode());
    }

    public function testADocumentHasNoFocusPointAndIsReplacedOnlyByADocument(): void
    {
        $document = $this->document("%PDF-1.7\n%%EOF", 'alt.pdf');

        $this->assertInvalid(fn() => $this->runner()->run(new UpdateMedium($document->id, focusX: 0.2, focusY: 0.2)), '/focusX');
        $this->assertInvalid(fn() => $this->runner()->run(new ReplaceMedium($document->id, $this->receive($this->path('html'), '<p>x</p>', 'seite.html')['upload'])), '/file');

        $replaced = $this->runner()->run(new ReplaceMedium($document->id, $this->receive($this->path('txt'), 'Neu', 'neu.txt')['upload']));
        self::assertSame(['text/plain', 'TXT'], [$replaced->type, $replaced->format]);
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
     * A ZIP archive with stored entries, enough to tell its kind.
     *
     * @param array<string, string> $entries
     */
    private static function zip(array $entries): string
    {
        $zip = '';

        foreach ($entries as $name => $data) {
            $zip .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, crc32($data), \strlen($data), \strlen($data), \strlen($name), 0) . $name . $data;
        }

        return $zip;
    }

    /**
     * Publishes a page with the file field and gives its HTML for guests.
     *
     * @param array<string, string> $download
     */
    private function publishWith(array $download): string
    {
        $page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Downloads'], 'areas' => ['main' => [
            ['block' => 'acme/media/Gallery', 'fields' => ['download' => $download]],
        ]]]]]))->id;
        $this->runner()->run(new PublishPages([$page]));
        $this->get(Urls::class)->clear();

        return (string) $this->get(PageHandler::class)->handle(new Psr17Factory()->createServerRequest('GET', 'https://example.org/'))->getBody();
    }

    /**
     * @param array<mixed> $texts
     */
    private function document(string $bytes, string $name, array $texts = []): MediumDetails
    {
        return $this->runner()->run(new CreateMedium($this->receive($this->path('bin'), $bytes, $name)['upload'], texts: $texts));
    }

    /**
     * @return array{upload: string, name: string, size: int, complete: true}|array{upload: string, received: int, complete: false}
     */
    private function receive(string $file, string $bytes, string $name): array
    {
        file_put_contents($file, $bytes);
        $request = new Psr17Factory()->createServerRequest('POST', 'http://localhost/')
            ->withUploadedFiles([UploadReceiver::FIELD => new UploadedFile($file, \strlen($bytes), \UPLOAD_ERR_OK, $name)]);

        return $this->get(UploadReceiver::class)->receive($request, $this->get(CurrentContext::class)->get()->actor);
    }

    private function path(string $extension): string
    {
        $file = $this->get(ProjectPaths::class)->var('test-' . bin2hex(random_bytes(4)) . '.' . $extension);
        @mkdir(\dirname($file), 0o775, true);

        return $file;
    }

    /**
     * @param list<string> $roles
     */
    private function token(array $roles): string
    {
        $user = $this->runner()->run(new CreateUser('document' . ++$this->users . '@example.com', 'User', roles: $roles), Context::system())->user;

        return $this->get(AccessTokens::class)->issue($user->id, TokenType::Api)->token;
    }

    private function deliver(string $path, ?string $token = null): ResponseInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', 'https://example.org' . $path);

        return $this->send(null === $token ? $request : $request->withHeader('Authorization', 'Bearer ' . $token));
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
