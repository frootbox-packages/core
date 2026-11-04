<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

/**
 * The headings of one rendered page (decisions 0129 and 0257). Each
 * heading asks for the level that fits where it stands; the outline gives
 * it the level it gets, so whatever the order of the blocks, a page has at
 * most one h1 and never skips a level:
 *
 * - at most one level deeper than the heading it belongs to: the last one
 *   before that asked for a higher level, so the first one is the h1
 *   unless it stands in an area with a fixed level, and headings that ask
 *   for the same level get the same level
 * - never 1 again after the first h1
 * - never deeper than 6
 */
final class Outline
{
    /**
     * The open headings, each deeper than the one before: what it asked
     * for and what it got.
     *
     * @var list<array{int, int}>
     */
    private array $open = [];

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
        $parent = 0;

        foreach (array_reverse($this->open) as [$asked, $got]) {
            if ($asked < $wanted) {
                $parent = $got;

                break;
            }
        }

        $level = max(min(max(1, $wanted), $parent + 1), $floor);
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

        while ([] !== $this->open && (end($this->open)[0] >= $wanted || end($this->open)[1] >= $level)) {
            array_pop($this->open);
        }

        $this->open[] = [$wanted, $level];
        $this->main = $this->main || 1 === $level;
        $this->headings[] = new RenderedHeading($level, $wanted, self::plain($text), $block, $field, $rank);

        return $level;
    }

    /**
     * The open headings so far, each deeper than the one before: the level
     * it asked for and the level it got. Empty at the start of the page.
     *
     * @return list<array{int, int}>
     */
    public function open(): array
    {
        return $this->open;
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
