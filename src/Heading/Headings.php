<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Heading;

/**
 * The headings of one block or layout while it is rendered (decision
 * 0129). A heading has a rank: 0 for the main heading of the block, 1 and
 * deeper for those below it. The level follows from where the block
 * stands:
 *
 * - The main heading wants the level of the block.
 * - Rank 1 and deeper want one level per rank below the main heading, or
 *   start at the level of the block if it has none.
 * - An area inside starts below the main heading, or at the level of the
 *   block if it has none, unless the schema fixes its level.
 */
final class Headings
{
    private ?int $main = null;

    /**
     * @param int         $start The level of the block
     * @param string|null $block Its id, for the outline
     * @param int         $floor No heading goes higher, in an area with a fixed level
     */
    public function __construct(
        public readonly Outline $outline = new Outline(),
        private readonly int $start = 1,
        private readonly ?string $block = null,
        private readonly int $floor = 1,
    ) {}

    /**
     * The level a heading of the rank asks for.
     */
    public function wanted(int $rank): int
    {
        $rank = max(0, $rank);
        $wanted = null === $this->main ? $this->start + max(0, $rank - 1) : $this->main + $rank;

        // Once the page has its h1, a place that would start at 1 starts at 2.
        return 1 === $wanted && $this->outline->hasMain() ? 2 : $wanted;
    }

    /**
     * The level it would get, without counting it.
     */
    public function level(int $rank): int
    {
        return $this->outline->level($this->wanted($rank), $this->floor);
    }

    /**
     * What an editor needs to give headings in rich text the level the
     * page gives them: the level each rank wants, the level before, the
     * floor and whether the page has its h1.
     *
     * @return array{wanted: list<int>, last: int, floor: int, main: bool}
     */
    public function flow(): array
    {
        return ['wanted' => [$this->wanted(1), $this->wanted(2), $this->wanted(3)], 'last' => $this->outline->last(), 'floor' => $this->floor, 'main' => $this->outline->hasMain()];
    }

    /**
     * Counts a heading and returns its level.
     *
     * @param string $text  Plain text or HTML
     * @param string $field Path of the field, e.g. "title"
     */
    public function take(int $rank, string $text, string $field): int
    {
        $level = $this->outline->take($this->wanted($rank), $text, $this->block, $field, $rank, $this->floor);

        if (0 === $rank && null === $this->main) {
            $this->main = $level;
        }

        return $level;
    }

    /**
     * The headings of a block placed in an area of this one.
     *
     * @param int|null $level The level the schema fixes for the area
     */
    public function inArea(?int $level, ?string $block): self
    {
        return new self($this->outline, $level ?? (null === $this->main ? $this->start : $this->main + 1), $block, $level ?? $this->floor);
    }
}
