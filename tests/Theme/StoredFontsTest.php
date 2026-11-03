<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Theme;

use Frootbox\Core\Design\WebFonts;
use Frootbox\Core\ProjectPaths;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Theme\Font;
use Frootbox\Core\Theme\FontFile;
use Frootbox\Core\Theme\StoredFonts;
use Frootbox\Core\Theme\ThemeChain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StoredFonts::class)]
#[CoversClass(ThemeChain::class)]
final class StoredFontsTest extends NotesTestCase
{
    public function testFilesAreKeptInStorageAndCopiedForVisitors(): void
    {
        $fonts = $this->get(StoredFonts::class);
        $paths = $this->get(ProjectPaths::class);

        $file = $fonts->save('acme/fonts', 'serif-latin.woff2', 'wOF2 one');
        self::assertSame($paths->storage('fonts/acme/fonts/serif-latin.woff2'), $file);
        self::assertFileDoesNotExist($paths->public('media/fonts/acme/fonts/serif-latin.woff2'), 'Copied only when shown.');

        self::assertSame('/media/fonts/acme/fonts/serif-latin.woff2?v=' . filemtime($file), $fonts->url('acme/fonts', 'serif-latin.woff2'));
        self::assertSame('wOF2 one', file_get_contents($paths->public('media/fonts/acme/fonts/serif-latin.woff2')));

        $fonts->save('acme/fonts', 'serif-latin.woff2', 'wOF2 two');
        $fonts->url('acme/fonts', 'serif-latin.woff2');
        self::assertSame('wOF2 two', file_get_contents($paths->public('media/fonts/acme/fonts/serif-latin.woff2')), 'A new file replaces the copy.');

        unlink($paths->public('media/fonts/acme/fonts/serif-latin.woff2'));
        $fonts->url('acme/fonts', 'serif-latin.woff2');
        self::assertFileExists($paths->public('media/fonts/acme/fonts/serif-latin.woff2'), 'Back after a restore without public/media.');

        $fonts->remove('acme/fonts', 'serif-latin.woff2');
        self::assertFileDoesNotExist($file);
        self::assertFileDoesNotExist($paths->public('media/fonts/acme/fonts/serif-latin.woff2'));
    }

    public function testNamesStayInTheirFolder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->get(StoredFonts::class)->save('acme/fonts', '../config/app.yaml', 'x');
    }

    public function testFontsOfExtensionsJoinThoseOfTheThemes(): void
    {
        $file = $this->get(StoredFonts::class)->save('acme/fonts', 'grotesk-latin.woff2', 'wOF2');
        $font = new Font('acme-grotesk', 'Grotesk', '"Grotesk", sans-serif', 'Grotesk', [new FontFile($file, '100 900', url: '/media/fonts/acme/fonts/grotesk-latin.woff2?v=1')]);
        $chain = $this->get(ThemeChain::class)->withFonts([$font]);
        $this->container->set(ThemeChain::class, $chain);

        self::assertSame(['sans', 'serif', 'rounded', 'mono', 'acme-grotesk'], \array_slice(array_keys($chain->fonts()), 0, 5), 'After the system fonts, before those of the themes.');
        $webFonts = $this->container->make(WebFonts::class);
        self::assertInstanceOf(WebFonts::class, $webFonts);
        self::assertStringContainsString('@font-face{font-family:"Grotesk";src:url("/media/fonts/acme/fonts/grotesk-latin.woff2?v=1") format("woff2");font-weight:100 900;', $webFonts->css());
    }
}
