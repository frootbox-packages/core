<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Twig\Markup;

/**
 * Leaves out the elements of empty fields for visitors (decision 0144).
 * A template writes <p>{{ field('text') }}</p> without a condition: in the
 * editing mode the paragraph stays and shows its placeholder, for
 * visitors an empty field prints a mark, and the element around it goes
 * when it holds nothing else. Elements that only held such elements go
 * as well, e.g. a header with an empty title and intro.
 */
final class EmptyElements
{
    public const string MARK = '<!--fb:empty-->';

    private const string ESCAPED = '&lt;!--fb:empty--&gt;';

    /** An element with nothing but marks and space inside. */
    private const string PATTERN = '~<([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^<>]*)?>(?:\s*<!--fb:empty-->)+\s*</\1>~';

    public static function mark(): Markup
    {
        return new Markup(self::MARK, 'UTF-8');
    }

    public static function remove(string $html): string
    {
        if (!str_contains($html, self::MARK) && !str_contains($html, self::ESCAPED)) {
            return $html;
        }

        // An element that goes leaves a mark, so its parent can go too.
        do {
            $before = $html;
            $html = preg_replace(self::PATTERN, self::MARK, $html) ?? $before;
        } while ($html !== $before);

        // Marks in text or attributes, e.g. from a filter, are dropped.
        return str_replace([self::MARK, self::ESCAPED], '', $html);
    }
}
