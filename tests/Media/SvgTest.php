<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Media;

use Frootbox\Core\Media\Svg;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SVG without anything that runs (decision 0182).
 */
#[CoversClass(Svg::class)]
final class SvgTest extends TestCase
{
    private const string OPEN = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function attacks(): iterable
    {
        yield 'script' => ['<script>alert(1)</script><rect width="1" height="1"/>', 'alert'];
        yield 'script in another case' => ['<SCRIPT>alert(1)</SCRIPT><rect width="1" height="1"/>', 'alert'];
        yield 'event handler' => ['<rect width="1" height="1" onclick="alert(1)"/>', 'alert'];
        yield 'event handler on an inner svg' => ['<svg onload="alert(1)"><rect width="1" height="1"/></svg>', 'alert'];
        yield 'foreignObject' => ['<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="javascript:alert(1)"/></foreignObject><rect width="1" height="1"/>', 'javascript'];
        yield 'link' => ['<a href="javascript:alert(1)"><circle r="1"/></a><rect width="1" height="1"/>', 'javascript'];
        yield 'use of another file' => ['<use href="https://evil.example/x.svg#a"/><rect width="1" height="1"/>', 'evil'];
        yield 'xlink to another file' => ['<use xlink:href="https://evil.example/x.svg#a"/><rect width="1" height="1"/>', 'evil'];
        yield 'image from elsewhere' => ['<image href="https://evil.example/track.png" width="1" height="1"/><rect width="1" height="1"/>', 'evil'];
        yield 'image as SVG data' => ['<image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=" width="1" height="1"/><rect width="1" height="1"/>', 'svg+xml'];
        yield 'animation of a link' => ['<a><set attributeName="href" to="javascript:alert(1)"/></a><rect width="1" height="1"/>', 'javascript'];
        yield 'animate' => ['<rect width="1" height="1"><animate attributeName="x" values="javascript:alert(1)"/></rect>', 'javascript'];
        yield 'fill from elsewhere' => ['<rect width="1" height="1" fill="url(https://evil.example/a.svg#g)"/>', 'evil'];
        yield 'CSS import' => ['<style>@import url(https://evil.example/a.css); rect { fill: red }</style><rect width="1" height="1"/>', 'evil'];
        yield 'CSS url in a rule' => ['<style>rect { background: url("https://evil.example/t.png") }</style><rect width="1" height="1"/>', 'evil'];
        yield 'CSS url in style' => ['<rect width="1" height="1" style="fill:red;background:url(//evil.example/t.png)"/>', 'evil'];
        yield 'CSS expression' => ['<rect width="1" height="1" style="width:expression(alert(1))"/>', 'alert'];
        yield 'CSS breaking out' => ['<style>rect { fill: red }&lt;/style&gt;&lt;script&gt;alert(1)&lt;/script&gt;</style><rect width="1" height="1"/>', '<script'];
        yield 'font from elsewhere' => ['<style>@font-face { font-family: x; src: url(https://evil.example/f.woff) }</style><text>Hi</text>', 'evil'];
        yield 'XHTML inside' => ['<html:script xmlns:html="http://www.w3.org/1999/xhtml">alert(1)</html:script><rect width="1" height="1"/>', 'alert'];
        yield 'xml:base' => ['<g xml:base="https://evil.example/"><use href="#a"/></g><rect id="a" width="1" height="1"/>', 'evil'];
        yield 'processing instruction' => ['<?xml-stylesheet href="https://evil.example/a.xsl"?><rect width="1" height="1"/>', 'evil'];
    }

    #[DataProvider('attacks')]
    public function testRemovesWhatRuns(string $inner, string $trace): void
    {
        $clean = Svg::clean(self::OPEN . $inner . '</svg>');

        self::assertNotNull($clean);
        self::assertStringNotContainsStringIgnoringCase($trace, $clean);
        self::assertStringContainsString('<rect', $clean . (str_contains($inner, '<text') ? '<rect' : ''));
    }

    public function testRefusesEntitiesAndWhatIsNoGraphic(): void
    {
        self::assertNull(Svg::clean('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&b;</text></svg>'));
        self::assertNull(Svg::clean('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'));
        self::assertNull(Svg::clean('<html><body>Hi</body></html>'));
        self::assertNull(Svg::clean('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Nothing to see is left.');
        self::assertNull(Svg::clean('<svg xmlns="http://www.w3.org/2000/svg"><rect'));
        self::assertNull(Svg::clean('<x:svg xmlns:x="urn:other"><rect/></x:svg>'));
        self::assertNull(Svg::clean(str_repeat(' ', 6 * 1024 * 1024)));
    }

    public function testKeepsWhatDrawingProgramsWrite(): void
    {
        $illustrator = <<<'SVG'
            <?xml version="1.0" encoding="UTF-8"?>
            <!-- Generator: Adobe Illustrator 28.0.0 -->
            <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" version="1.1" id="Ebene_1" x="0px" y="0px" viewBox="0 0 200 100" xml:space="preserve" data-name="Logo" inkscape:version="1.3">
              <style type="text/css">.st0{fill:url(#verlauf);}.st1{fill:#1D4ED8;stroke:#000;stroke-width:2;}</style>
              <defs><linearGradient id="verlauf" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#f59e0b"/><stop offset="1" stop-color="#b45309"/></linearGradient></defs>
              <inkscape:path d="M0 0"/>
              <path class="st0" d="M10 10h80v80H10z"/>
              <circle class="st1" cx="150" cy="50" r="40" clip-path="url(#c)"/>
              <use xlink:href="#verlauf"/>
              <image width="10" height="10" href="data:image/png;base64,iVBORw0KGgo="/>
              <text x="10" y="95" font-family="Inter" font-size="12">Röstung</text>
            </svg>
            SVG;

        $clean = Svg::clean($illustrator);
        self::assertNotNull($clean);
        self::assertStringStartsWith('<svg', $clean);
        self::assertStringNotContainsString('<?xml', $clean);
        self::assertStringNotContainsString('<!DOCTYPE', $clean);
        self::assertStringNotContainsString('Generator', $clean);
        self::assertStringNotContainsString('data-name', $clean);
        self::assertStringNotContainsString('inkscape:version', $clean);
        self::assertStringNotContainsString('<inkscape:path', $clean);
        self::assertStringContainsString('.st0{fill:url(#verlauf);}', $clean);
        self::assertStringContainsString('<linearGradient id="verlauf"', $clean);
        self::assertStringContainsString('xlink:href="#verlauf"', $clean);
        self::assertStringContainsString('href="data:image/png;base64,iVBORw0KGgo="', $clean);
        self::assertStringContainsString('>Röstung</text>', $clean);
        self::assertSame([200, 100], Svg::size($clean));
    }

    public function testAddsTheNamespaceAFileForgot(): void
    {
        $clean = Svg::clean('<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');

        self::assertNotNull($clean);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $clean);
    }

    public function testTellsTheSize(): void
    {
        self::assertSame([120, 60], Svg::size('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60"/>'));
        self::assertSame([160, 80], Svg::size('<svg xmlns="http://www.w3.org/2000/svg" width="120pt" height="60pt"/>'));
        self::assertSame([24, 12], Svg::size('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 12"/>'));
        self::assertSame([48, 24], Svg::size('<svg xmlns="http://www.w3.org/2000/svg" width="48" viewBox="0 0 24 12"/>'));
        self::assertSame([24, 12], Svg::size('<svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 24 12"/>'));
        self::assertSame([300, 150], Svg::size('<svg xmlns="http://www.w3.org/2000/svg"/>'));
    }

    public function testTellsAnSvgFileByItsStart(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'svg');
        self::assertIsString($file);

        file_put_contents($file, "\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- a -->\n<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"x.dtd\">\n<svg viewBox=\"0 0 1 1\"/>");
        self::assertTrue(Svg::isSvg($file));
        file_put_contents($file, '<html><svg/></html>');
        self::assertFalse(Svg::isSvg($file));
        unlink($file);
    }

    public function testBindsIdsClassesAndRulesToTheOneGraphic(): void
    {
        $clean = Svg::clean(self::OPEN . '<style>.a{fill:url(#g)} path, #b { stroke: red } @media (min-width: 1px) { .a { fill: blue } }</style><defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs><path id="b" class="a big" d="M0 0h1v1z" style="fill:url(#g)"/><use href="#b"/></svg>');
        self::assertNotNull($clean);

        $inline = Svg::inline($clean, 'fbi-1');
        self::assertStringStartsWith('<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"', $inline);
        self::assertStringContainsString('fill="currentColor"', $inline);
        self::assertStringContainsString('data-fb-svg="fbi-1"', $inline);
        self::assertStringContainsString('<linearGradient id="fbi-1-g">', $inline);
        self::assertStringContainsString('id="fbi-1-b" class="fbi-1-a fbi-1-big"', $inline);
        self::assertStringContainsString('style="fill:url(#fbi-1-g)"', $inline);
        self::assertStringContainsString('<use href="#fbi-1-b"/>', $inline);
        self::assertStringContainsString('[data-fb-svg="fbi-1"] .fbi-1-a{fill:url(#fbi-1-g)}', $inline);
        self::assertStringContainsString('[data-fb-svg="fbi-1"] path, [data-fb-svg="fbi-1"] #fbi-1-b{ stroke: red }', $inline);
        self::assertStringNotContainsString('@media', $inline);
    }

    public function testKeepsTheFillOfAGraphic(): void
    {
        $inline = Svg::inline('<svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" role="img" aria-label="x"><path d="M0 0"/></svg>', 'p');

        self::assertSame('<svg aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" data-fb-svg="p"><path d="M0 0"/></svg>', $inline);
    }
}
