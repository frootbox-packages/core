<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

/**
 * The headings of one rendered page (decision 0129). Each heading asks for
 * the level that fits where it stands; the outline gives it the level it
 * gets, so whatever the order of the blocks, a page has at most one h1 and
 * never skips a level:
 *
 * - at most one level deeper than the heading before, so the first one is
 *   the h1 unless it stands in an area with a fixed level
 * - never 1 again after the first h1
 * - never deeper than 6
 */
final class Outline
{
    private int $last = 0;

    private bool $main = false;

    /** @var list<RenderedHeading> */
    private array $headings = [];

    /**
     * The level a heading would get, without counting it, e.g. for an
     * empty field in the editing mode.
     *
     * @param int $floor The level of an area that never has the h1, e.g. a sidebar
     */
    public function level(int $wanted, int $floor = 1): int
    {
        $level = max(min(max(1, $wanted), $this->last + 1), $floor);
        $level = $this->main && 1 === $level ? 2 : $level;

        return min($level, 6);
    }

    /**
     * Counts a heading and returns its level.
     *
     * @param string      $text  Plain text
     * @param string|null $field Path of the field inside the block, e.g. "items/0/question"
     */
    public function take(int $wanted, string $text, ?string $block = null, ?string $field = null, ?int $rank = null, int $floor = 1): int
    {
        $level = $this->level($wanted, $floor);
        $this->last = $level;
        $this->main = $this->main || 1 === $level;
        $this->headings[] = new RenderedHeading($level, $wanted, self::plain($text), $block, $field, $rank);

        return $level;
    }

    /**
     * Whether the page has its h1.
     */
    public function hasMain(): bool
    {
        return $this->main;
    }

    /**
     * @return list<RenderedHeading> in the order they were rendered
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * Text without tags and entities, on one line, as it is compared with
     * the rendered page.
     */
    public static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }
}
