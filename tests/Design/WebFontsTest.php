<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Design;

use Frootbox\Core\Design\PublishDesign;
use Frootbox\Core\Design\UpdateDesign;
use Frootbox\Core\Design\WebFonts;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\Theme\Font;
use Frootbox\Core\Theme\FontFallback;
use Frootbox\Core\Theme\FontFile;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\ThemeException;
use Frootbox\Core\Theme\UnicodeRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(WebFonts::class)]
#[CoversClass(Font::class)]
#[CoversClass(FontFile::class)]
#[CoversClass(FontFallback::class)]
#[CoversClass(UnicodeRange::class)]
final class WebFontsTest extends NotesTestCase
{
    public function testWritesTheFacesOfFontsWithFiles(): void
    {
        $css = $this->get(WebFonts::class)->css();

        self::assertMatchesRegularExpression('#^@font-face\{font-family:"Test Sans";src:url\("/assets/acme/lettered/assets/fonts/test-sans-latin\.woff2\?v=\d+"\) format\("woff2"\);font-weight:100 900;font-style:normal;font-display:swap;unicode-range:U\+0000-00FF, #', $css);
        self::assertStringContainsString('font-weight:400;font-style:italic;font-display:swap;unicode-range:U+0600-06FF;}', $css);
        self::assertStringEndsWith('@font-face{font-family:"Test Sans Fallback";src:local("Arial"),local("Helvetica");size-adjust:105.37%;ascent-override:101.45%;descent-override:27.81%;}', $css);
        self::assertStringNotContainsString('Lora', $css, 'A font without files is the theme\'s own business.');

        $font = $this->get(ThemeChain::class)->fonts()['test-sans'];
        self::assertSame('"Test Sans", "Test Sans Fallback", system-ui, sans-serif', $font->family, 'The fallback comes right after the font.');
    }

    public function testPreloadsOnlyWhatTheTokensUse(): void
    {
        $fonts = $this->get(WebFonts::class);
        $preloads = $fonts->preloads();

        self::assertCount(1, $preloads, 'Only the file marked for preloading.');
        self::assertMatchesRegularExpression('#^<link rel="preload" href="/assets/acme/lettered/assets/fonts/test-sans-latin\.woff2\?v=\d+" as="font" type="font/woff2" crossorigin>$#', $preloads[0]);

        $this->runner()->run(new UpdateDesign(['font-heading' => 'serif']));
        self::assertSame($preloads, $this->get(WebFonts::class)->preloads(), 'Visitors keep the published font while editors try another.');

        $this->runner()->run(new PublishDesign());
        self::assertSame([], $this->get(WebFonts::class)->preloads(), 'Once the other font is published.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenFonts(): iterable
    {
        yield 'missing file' => ["files: [{ file: assets/fonts/none.woff2 }]", 'file 1: assets/fonts/none.woff2 does not exist.'];
        yield 'outside assets' => ["files: [{ file: fonts/a.woff2 }]", '"file" must be a .woff2, .woff, .ttf or .otf below assets/'];
        yield 'unknown range' => ["files: [{ file: assets/fonts/test-sans-latin.woff2, range: klingon }]", '"range" must be one of latin, latin-ext'];
        yield 'bad weight' => ["files: [{ file: assets/fonts/test-sans-latin.woff2, weight: bold }]", '"weight" must be a weight such as 400'];
        yield 'fallback without files' => ['fallback: { local: Arial }', 'has a "fallback" but no "files"'];
        yield 'bad percentage' => ["files: [{ file: assets/fonts/test-sans-latin.woff2 }]\n      fallback: { local: Arial, ascent: 1.2 }", '"fallback.ascent" must be a percentage'];
    }

    #[DataProvider('brokenFonts')]
    public function testExplainsBrokenFonts(string $yaml, string $message): void
    {
        $theme = Yaml::parse("fonts:\n  x:\n    label: X\n    family: '\"X\", sans-serif'\n    " . str_replace("\n      ", "\n    ", $yaml) . "\n");
        self::assertIsArray($theme);
        $package = new ExtensionPackage('acme/x', FixtureSite::path('lettered'), 'X', theme: $theme);

        $this->expectException(ThemeException::class);
        $this->expectExceptionMessage($message);

        Theme::fromPackage($package);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'lettered');
    }

    protected function config(): array
    {
        return ['theme' => 'acme/lettered', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }
}
