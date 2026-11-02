<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Help;

use Frootbox\Core\Help\Markdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Markdown::class)]
final class MarkdownTest extends TestCase
{
    public function testMakesTheLargestHeadingAnH2(): void
    {
        self::assertSame("<h2 id=\"move\">Move</h2>\n<h3 id=\"with-the-keys\">With the keys</h3>", self::html("## Move\n\n### With the keys"));
        self::assertSame('<h3 id="größe">Größe</h3>', Markdown::toHtml('# Größe', self::none(...), self::none(...), 3));
    }

    public function testWritesBlocks(): void
    {
        $html = self::html(<<<'MD'
            First line
            goes on.

            - one
              still one
            - two

            > Note: **careful**

            ```
            <b>as it is</b> *no mark*
            ```
            MD);

        self::assertSame(implode("\n", [
            '<p>First line goes on.</p>',
            '<ul><li>one still one</li><li>two</li></ul>',
            '<blockquote><p>Note: <strong>careful</strong></p></blockquote>',
            '<pre><code>&lt;b&gt;as it is&lt;/b&gt; *no mark*</code></pre>',
        ]), $html);
    }

    public function testMarksTextInsideALine(): void
    {
        self::assertSame('<p><em>a</em>, <strong>b</strong>, <code>*c*</code>, snake_case_name, 2 * 3 * 4</p>', self::html('*a*, **b**, `*c*`, snake_case_name, 2 * 3 * 4'));
        self::assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', self::html('<img src=x onerror=alert(1)>'));
    }

    public function testKeepsLinksSafe(): void
    {
        $link = static fn(string $href): ?string => 'other.md' === $href ? '/help/other' : null;

        self::assertSame('<p><a href="/help/other">Other</a>, Gone, <a href="#a-place">here</a>, Script, Data</p>', Markdown::toHtml('[Other](other.md), [Gone](gone.md), [here](#A-Place), [Script](javascript:alert), [Data](data:text/html)', $link, self::none(...)));
        self::assertSame('<p></p>', Markdown::toHtml('![x](https://example.org/a.png)![y](/etc/passwd)![z](../secret.png)', $link, static fn(string $src): string => '/x/' . $src));
    }

    public function testEscapesGeneratedText(): void
    {
        $title = '*Teaser* [new] #1 - `code`';

        self::assertSame('<p>*Teaser* [new] #1 - `code`</p>', self::html(Markdown::escape($title)));
        self::assertSame('<h2 id="a-b">A_b</h2>', self::html('# ' . Markdown::escape('A_b')));
    }

    public function testGivesTheTextForTheSearch(): void
    {
        self::assertSame("Move\nDrag a page, see Pages.\nOne", Markdown::toText("## Move\nDrag a **page**, see [Pages](pages.md).\n- One"));
    }

    private static function html(string $markdown): string
    {
        return Markdown::toHtml($markdown, self::none(...), self::none(...));
    }

    private static function none(string $href): null
    {
        return null;
    }
}
