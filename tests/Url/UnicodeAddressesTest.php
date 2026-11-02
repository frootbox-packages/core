<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Url;

use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Url\CreateRedirect;
use Frootbox\Core\Url\NotFoundLog;
use Frootbox\Core\Url\Target;
use Frootbox\Core\Url\UrlPath;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\View\Delivery;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

/**
 * Addresses in Arabic (decision 0158): stored as text, over HTTP
 * percent-encoded.
 */
#[CoversClass(UrlPath::class)]
#[CoversClass(Slug::class)]
#[CoversClass(Urls::class)]
final class UnicodeAddressesTest extends NotesTestCase
{
    private const string ABOUT = '%D9%85%D9%86-%D9%86%D8%AD%D9%86';

    private string $about;

    protected function setUp(): void
    {
        parent::setUp();

        $start = $this->createPage(null, ['de' => 'Start', 'ar' => 'الرئيسية']);
        $this->about = $this->createPage($start, ['de' => 'Über uns', 'ar' => 'من نحن']);
        $this->runner()->run(new PublishPages([$start, $this->about]));
    }

    public function testFindsPagesByTheirAddressInArabic(): void
    {
        self::assertSame('/ar/من-نحن', $this->get(Urls::class)->path(Target::page($this->about), 'ar'), 'As text inside.');

        $html = $this->html('/ar/' . self::ABOUT);
        self::assertStringContainsString('<link rel="canonical" href="https://example.org/ar/' . self::ABOUT . '">', $html, 'Encoded over HTTP.');
        self::assertStringContainsString('<link rel="alternate" hreflang="ar" href="https://example.org/ar/' . self::ABOUT . '">', $html);

        $this->get(Delivery::class)->set($this->get(Languages::class)->get('ar'));
        self::assertSame('/ar/' . self::ABOUT, $this->get(Urls::class)->href(Target::page($this->about)));

        self::assertSame(200, $this->request('/ar/' . strtolower(self::ABOUT))->getStatusCode(), 'Hex in lower case is the same page.');
    }

    public function testRedirectsFromTheOldAddress(): void
    {
        $this->runner()->run(new UpdatePage($this->about, ['ar' => ['slug' => 'عن-المقهى']]));
        $this->runner()->run(new PublishPages([$this->about]));

        $response = $this->request('/ar/' . self::ABOUT);
        self::assertSame(301, $response->getStatusCode());
        self::assertSame('/ar/%D8%B9%D9%86-%D8%A7%D9%84%D9%85%D9%82%D9%87%D9%89', $response->getHeaderLine('Location'));

        $this->runner()->run(new CreateRedirect('/ar/قديم', location: '/ar/عن-المقهى'));
        $manual = $this->request('/ar/%D9%82%D8%AF%D9%8A%D9%85');
        self::assertSame(301, $manual->getStatusCode());
        self::assertSame('/ar/%D8%B9%D9%86-%D8%A7%D9%84%D9%85%D9%82%D9%87%D9%89', $manual->getHeaderLine('Location'));
    }

    public function testAnAddressInOtherLettersIsChecked(): void
    {
        // "한" with its letters apart, as a Mac may send it.
        $this->runner()->run(new UpdatePage($this->about, ['ar' => ['slug' => "\u{1112}\u{1161}\u{11AB}"]]));
        $this->runner()->run(new PublishPages([$this->about]));
        self::assertSame('/ar/한', $this->get(Urls::class)->path(Target::page($this->about), 'ar'), 'Typed letters in NFC.');

        $this->expectExceptionMessage('Latin, Cyrillic and Greek only as a-z');
        $this->runner()->run(new UpdatePage($this->about, ['ar' => ['slug' => 'Über']]));
    }

    public function testBrokenBytesFindNothing(): void
    {
        try {
            $this->request('/ar/%FF%FE');
            self::fail('No page for broken bytes.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }

        try {
            $this->request('/ar/%D9%84%D8%A7');
        } catch (HttpException) {
        }

        self::assertContains('/ar/لا', array_map(static fn($entry): string => $entry->path, $this->get(NotFoundLog::class)->entries()), 'The log shows the text.');
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return [
            'app' => ['name' => 'Testsite', 'url' => 'https://example.org'],
            'theme' => 'acme/site',
            'languages' => ['de' => ['title' => 'Deutsch'], 'ar' => ['title' => 'العربية']],
        ];
    }

    /**
     * @param array<string, string> $titles
     */
    private function createPage(?string $parentId, array $titles): string
    {
        $translations = array_map(static fn(string $title): array => ['title' => $title, 'content' => ['fields' => ['heading' => $title]]], $titles);

        return $this->runner()->run(new CreatePage('acme/base/Page', $translations, $parentId))->id;
    }

    private function request(string $path): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }

    private function html(string $path): string
    {
        $response = $this->request($path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }
}
