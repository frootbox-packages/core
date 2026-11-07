<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Theme\SiteIcon;
use Frootbox\Core\Theme\SiteIconHandler;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SiteIcon::class)]
#[CoversClass(SiteIconHandler::class)]
final class SiteIconTest extends NotesTestCase
{
    private string $theme = 'acme/iconic';

    public function testEveryPageNamesTheIcon(): void
    {
        $id = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Start']]]]))->id;
        $this->runner()->run(new PublishPages([$id]));
        $html = (string) $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org/'))->getBody();
        $version = $this->get(SiteIcon::class)->version();

        self::assertNotNull($version);
        self::assertStringContainsString('<link rel="icon" href="/favicon.ico?v=' . $version . '" sizes="48x48">', $html);
        self::assertStringContainsString('<link rel="icon" href="/icon-192.png?v=' . $version . '" type="image/png" sizes="192x192">', $html);
        self::assertStringContainsString('<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=' . $version . '">', $html);
        self::assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest?v=' . $version . '">', $html);
    }

    public function testMakesAFaviconWithThreeSizes(): void
    {
        $response = $this->fetch('/favicon.ico');
        $ico = (string) $response->getBody();

        self::assertSame('image/x-icon', $response->getHeaderLine('Content-Type'));
        $header = unpack('vreserved/vtype/vcount', $ico);
        self::assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], $header);

        foreach ([16, 32, 48] as $index => $size) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', $ico, 6 + 16 * $index);
            self::assertIsArray($entry);
            self::assertSame($size, $entry['width']);
            $offset = $entry['offset'];
            $length = $entry['length'];
            self::assertIsInt($offset);
            self::assertIsInt($length);
            self::assertSame("\x89PNG", substr($ico, $offset, 4), 'Each size is a PNG inside.');
            self::assertSame([$size, $size], \array_slice((array) getimagesizefromstring(substr($ico, $offset, $length)), 0, 2));
        }
    }

    public function testMakesThePngSizes(): void
    {
        foreach (['/apple-touch-icon.png' => 180, '/icon-192.png' => 192, '/icon-512.png' => 512] as $address => $size) {
            $response = $this->fetch($address);

            self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
            self::assertSame([$size, $size], \array_slice((array) getimagesizefromstring((string) $response->getBody()), 0, 2), $address);
        }

        self::assertSame(304, $this->fetch('/icon-192.png', $this->fetch('/icon-192.png')->getHeaderLine('ETag'))->getStatusCode(), 'The browser keeps what it has.');

        self::assertSame(127, self::corner($this->fetch('/icon-192.png')), 'The icons of the manifest stay transparent.');
        self::assertSame(0, self::corner($this->fetch('/apple-touch-icon.png')), 'Apple devices would show transparency black.');
    }

    public function testDescribesTheWebsiteInTheManifest(): void
    {
        $response = $this->fetch('/manifest.webmanifest');
        $manifest = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        $version = $this->get(SiteIcon::class)->version();

        self::assertSame('application/manifest+json', $response->getHeaderLine('Content-Type'));
        self::assertIsArray($manifest);
        self::assertSame('Acme', $manifest['name'] ?? null);
        self::assertSame('#c00', $manifest['theme_color'] ?? null, 'The main colour of the theme.');
        self::assertIsArray($manifest['icons'] ?? null);
        self::assertSame(['src' => '/icon-512.png?v=' . $version, 'sizes' => '512x512', 'type' => 'image/png'], $manifest['icons'][1] ?? null);
    }

    public function testWithoutAnIconThereIsNone(): void
    {
        $this->theme = 'acme/site';
        $this->setUp();

        self::assertSame([], $this->get(SiteIcon::class)->tags());
        $this->expectException(HttpException::class);
        $this->fetch('/favicon.ico');
    }

    public function testTheIconIsAPngInTheAssets(): void
    {
        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage('"icon" must be a PNG in an assets folder');

        Theme::fromPackage(new ExtensionPackage('acme/broken', FixtureSite::path('iconic'), 'Broken', theme: ['icon' => '../secret.png']));
    }

    protected function extensions(): Extensions
    {
        return new Extensions([...FixtureSite::extensions('base', 'site', 'iconic')->packages], 'fixtures');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Acme', 'url' => 'https://example.org'], 'theme' => $this->theme, 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /** The alpha of the top left pixel, 0 opaque to 127 transparent. */
    private static function corner(ResponseInterface $response): int
    {
        $image = imagecreatefromstring((string) $response->getBody());
        self::assertNotFalse($image);
        $color = imagecolorsforindex($image, (int) imagecolorat($image, 0, 0));

        return $color['alpha'];
    }

    private function fetch(string $address, string $etag = ''): ResponseInterface
    {
        $request = new ServerRequest('GET', 'https://example.org' . $address);

        return $this->get(SiteIconHandler::class)->handle('' === $etag ? $request : $request->withHeader('If-None-Match', $etag));
    }
}
