<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Component\Instance;
use Frootbox\Core\Page\Slug;
use WeakMap;

/**
 * The jump marks of the page being rendered (decision 0196). A block that
 * offers the option "jumpMark" gets an id from its text, e.g. "Für
 * Agenturen" becomes "fuer-agenturen", unique on the page. Templates read
 * them with jump_mark() for their own id and jump_marks() for a jump bar.
 */
final class JumpMarks
{
    /** The option a block offers for its jump mark. */
    public const string OPTION = 'jumpMark';

    /** Ids the themes use themselves, e.g. the target of "Skip to content". */
    private const array RESERVED = ['content', 'main'];

    /** @var list<array{id: string, label: string}> */
    private array $marks = [];

    /** @var WeakMap<Instance, string> */
    private WeakMap $ids;

    /** @var list<array{id: string, label: string}> */
    private array $standIn = [];

    public function __construct()
    {
        $this->ids = new WeakMap();
    }

    /**
     * Collects the marks of a layout or block and everything in its areas,
     * in the order they stand on the page.
     */
    public function collect(Instance $root, string $language = ''): void
    {
        $this->marks = [];
        $this->ids = new WeakMap();
        $taken = array_fill_keys(self::RESERVED, true);
        $this->walk($root, $language, $taken);
    }

    /**
     * The id of a block, null if it has no jump mark.
     */
    public function id(Instance $block): ?string
    {
        return $this->ids[$block] ?? null;
    }

    /**
     * The marks of the page, or those standing in for it when a block
     * has none around it.
     *
     * @return list<array{id: string, label: string}>
     */
    public function all(): array
    {
        return [] === $this->marks ? $this->standIn : $this->marks;
    }

    /**
     * Marks for blocks that render one by one, outside of a page: the
     * design page gives its own sections, so its jump bar has places to
     * go to (decision 0213). An empty list ends it.
     *
     * @param list<array{id: string, label: string}> $marks
     */
    public function standIn(array $marks): void
    {
        $this->standIn = $marks;
    }

    /**
     * @param array<string, true> $taken
     */
    private function walk(Instance $instance, string $language, array &$taken): void
    {
        $label = $instance->options[self::OPTION] ?? null;

        if (\is_string($label) && '' !== trim($label)) {
            $label = trim($label);
            $base = Slug::fromTitle($label, $language);
            $base = '' === $base ? 'section' : $base;
            $id = $base;

            for ($number = 2; isset($taken[$id]); ++$number) {
                $id = $base . '-' . $number;
            }

            $taken[$id] = true;
            $this->ids[$instance] = $id;
            $this->marks[] = ['id' => $id, 'label' => $label];
        }

        foreach ($instance->areas as $blocks) {
            foreach ($blocks as $block) {
                $this->walk($block, $language, $taken);
            }
        }
    }
}
