<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Help;

use Frootbox\RichText\Html;

/**
 * The Markdown of help pages as safe HTML (decision 0218): headings,
 * paragraphs, lists, quotes, code, emphasis, links and images. Every
 * text is escaped, HTML in the file shows as text. Links lead only to
 * other help pages, anchors or https; images only to files of the
 * package. The largest heading of the text becomes an h2 (or $top), so
 * it fits below the title of the page.
 */
final class Markdown
{
    /**
     * @param callable(string): ?string $link  the address of a relative link, e.g. "pages.md#move"; null drops it
     * @param callable(string): ?string $image the address of a relative image, e.g. "assets/tree.png"; null drops it
     */
    public static function toHtml(string $markdown, callable $link, callable $image, int $top = 2): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        // The largest heading of the text becomes $top, whether it starts with "#" or "##".
        preg_match_all('/^(#{1,6})\s/m', $markdown, $levels);
        $shift = $top - min([6, ...array_map(strlen(...), $levels[1])]);
        $html = [];
        $paragraph = [];
        $count = \count($lines);


        for ($i = 0; $i < $count; ++$i) {
            $line = rtrim($lines[$i]);

            if ('' === trim($line)) {
                [$html, $paragraph] = [self::paragraph($html, $paragraph, $link, $image), []];

                continue;
            }

            // Fenced code, as it is.
            if (1 === preg_match('/^\s*(```|~~~)/', $line, $fence)) {
                [$html, $paragraph] = [self::paragraph($html, $paragraph, $link, $image), []];
                $code = [];

                for (++$i; $i < $count && 1 !== preg_match('/^\s*' . preg_quote($fence[1], '/') . '/', $lines[$i]); ++$i) {
                    $code[] = $lines[$i];
                }

                $html[] = '<pre><code>' . Html::escape(implode("\n", $code)) . '</code></pre>';

                continue;
            }

            if (1 === preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $line, $heading)) {
                [$html, $paragraph] = [self::paragraph($html, $paragraph, $link, $image), []];
                $level = max(1, min(6, \strlen($heading[1]) + $shift));
                $html[] = \sprintf('<h%d id="%s">%s</h%1$d>', $level, Html::escape(self::anchor($heading[2])), self::inline($heading[2], $link, $image));

                continue;
            }

            if (str_starts_with(ltrim($line), '>')) {
                [$html, $paragraph] = [self::paragraph($html, $paragraph, $link, $image), []];
                $quote = [];

                for (; $i < $count && str_starts_with(ltrim($lines[$i]), '>'); ++$i) {
                    $quote[] = preg_replace('/^\s*>\s?/', '', $lines[$i]) ?? '';
                }

                --$i;
                $html[] = '<blockquote>' . self::toHtml(implode("\n", $quote), $link, $image, $top) . '</blockquote>';

                continue;
            }

            if (1 === preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line, $marker)) {
                [$html, $paragraph] = [self::paragraph($html, $paragraph, $link, $image), []];
                $ordered = ctype_digit(rtrim($marker[1], '.)'));
                $items = [];

                for (; $i < $count; ++$i) {
                    $current = rtrim($lines[$i]);

                    if (1 === preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $current, $item) && $ordered === ctype_digit(rtrim($item[1], '.)'))) {
                        $items[] = $item[2];
                    } elseif ('' !== trim($current) && [] !== $items && 1 === preg_match('/^\s+\S/', $current)) {
                        // A line that goes on with the item above.
                        $items[\count($items) - 1] .= ' ' . trim($current);
                    } else {
                        break;
                    }
                }

                --$i;
                $tag = $ordered ? 'ol' : 'ul';
                $html[] = '<' . $tag . '>' . implode('', array_map(static fn(string $item): string => '<li>' . self::inline($item, $link, $image) . '</li>', $items)) . '</' . $tag . '>';

                continue;
            }

            $paragraph[] = trim($line);
        }

        [$html, $paragraph] = [self::paragraph($html, $paragraph, $link, $image), []];

        return implode("\n", $html);
    }

    /**
     * The lines so far with the paragraph collected, if any.
     *
     * @param list<string>              $html
     * @param list<string>              $paragraph
     * @param callable(string): ?string $link
     * @param callable(string): ?string $image
     *
     * @return list<string>
     */
    private static function paragraph(array $html, array $paragraph, callable $link, callable $image): array
    {
        if ([] !== $paragraph) {
            $html[] = '<p>' . self::inline(implode(' ', $paragraph), $link, $image) . '</p>';
        }

        return $html;
    }

    /**
     * Text that shows as it is, e.g. a title in generated Markdown.
     */
    public static function escape(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]()#>!+\-])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * The anchor of a heading, e.g. "move-a-page" for "Move a page".
     */
    public static function anchor(string $text): string
    {
        $anchor = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '', '-'));

        return '' === $anchor ? 'section' : $anchor;
    }

    /**
     * The text without its marks, e.g. for the search.
     */
    public static function toText(string $markdown): string
    {
        $text = preg_replace(['/^\s*(```|~~~).*$/m', '/^\s*(#{1,6}|>|[-*+]|\d+[.)])\s+/m', '/!?\[([^\]]*)\]\([^)]*\)/', '/[*_`]+/'], ['', '', '$1', ''], $markdown);

        return trim($text ?? '');
    }

    /**
     * @param callable(string): ?string $link
     * @param callable(string): ?string $image
     */
    private static function inline(string $text, callable $link, callable $image): string
    {
        // Code first: nothing inside it is a mark.
        $parts = preg_split('/(?<!\\\\)(`[^`]+`)/', $text, flags: \PREG_SPLIT_DELIM_CAPTURE);
        $html = '';

        foreach (false === $parts ? [$text] : $parts as $part) {
            if (str_starts_with($part, '`') && str_ends_with($part, '`') && \strlen($part) > 1) {
                $html .= '<code>' . Html::escape(substr($part, 1, -1)) . '</code>';
            } else {
                $html .= self::marks($part, $link, $image);
            }
        }

        return $html;
    }

    /**
     * @param callable(string): ?string $link
     * @param callable(string): ?string $image
     */
    private static function marks(string $text, callable $link, callable $image): string
    {
        $tokens = [];
        $keep = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;

            return "\u{E000}" . (\count($tokens) - 1) . "\u{E001}";
        };

        // Escaped marks stay as they are.
        $text = preg_replace_callback('/\\\\([\\\\`*_\[\]()#>!+\-])/', static fn(array $match): string => $keep(Html::escape($match[1])), $text) ?? $text;

        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/u', static function (array $match) use ($image, $keep): string {
            $src = self::image($match[2], $image);

            return null === $src ? '' : $keep('<img src="' . Html::escape($src) . '" alt="' . Html::escape($match[1]) . '" loading="lazy">');
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function (array $match) use ($link, $image, $keep): string {
            $href = self::link($match[2], $link);
            $label = self::marks($match[1], $link, $image);

            if (null === $href) {
                return $keep($label);
            }

            $external = str_starts_with($href, 'https://');

            return $keep('<a href="' . Html::escape($href) . '"' . ($external ? ' rel="noopener noreferrer"' : '') . '>' . $label . '</a>');
        }, $text) ?? $text;

        $html = Html::escape($text);
        $html = preg_replace(['/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '/__(?=\S)(.+?)(?<=\S)__/u'], '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace(['/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u'], '<em>$1</em>', $html) ?? $html;

        return preg_replace_callback("/\u{E000}(\\d+)\u{E001}/u", static fn(array $match): string => $tokens[(int) $match[1]] ?? '', $html) ?? $html;
    }

    /**
     * @param callable(string): ?string $link
     */
    private static function link(string $href, callable $link): ?string
    {
        return match (true) {
            str_starts_with($href, '#') => '#' . self::anchor(substr($href, 1)),
            str_starts_with($href, 'https://') => $href,
            1 === preg_match('/^[a-z][a-z0-9+.-]*:|^\/\//i', $href) => null,
            default => $link($href),
        };
    }

    /**
     * @param callable(string): ?string $image
     */
    private static function image(string $src, callable $image): ?string
    {
        // Only files of the package: no tracking through images of others.
        return 1 === preg_match('/^[a-z][a-z0-9+.-]*:|^\/\/|^\//i', $src) || str_contains($src, '..') ? null : $image($src);
    }
}
