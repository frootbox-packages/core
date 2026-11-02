<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Theme;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\Range;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\I18n\Labels;

/**
 * The symbols an icon field can show, for choosing one (decisions 0179,
 * 0182): the sets with their number of symbols and one page of symbols,
 * in the order of the sets and within a set by label.
 *
 * @implements Query<IconList>
 */
#[AsQuery('icon.list', 'Lists the symbols an icon field can show, from the theme, extensions and the media library, with name, label and SVG; filtered by set, a search in labels and names or one name')]
final readonly class ListIcons implements Query, Scoped
{
    public function __construct(
        #[Description('Part of the label or name')]
        #[Length(max: 100)]
        public ?string $search = null,
        #[Description('Only this set, e.g. "theme", "media" or "acme/fontawesome/solid"; none for all')]
        public ?string $set = null,
        #[Description('Only the symbol of this name, e.g. to show the one a field holds')]
        public ?string $name = null,
        #[Range(1)]
        public int $page = 1,
        #[Range(1, 500)]
        public int $perPage = 120,
    ) {}

    /**
     * A role within a branch is enough to edit pages there.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    public function handle(Icons $icons, Labels $labels): IconList
    {
        $sets = [];
        $found = [];
        $search = mb_strtolower(trim($this->search ?? ''));

        foreach ($icons->sets() as $set => $about) {
            $names = $icons->names($set);
            $label = $labels->text($about['label'], $about['package']);
            $title = null === $about['title'] ? null : $labels->text($about['title'], $about['package']);
            $sets[] = new IconSetSummary($set, null === $title ? $label : $title . ': ' . $label, \count($names));

            if (null !== $this->set && $set !== $this->set) {
                continue;
            }

            $list = [];

            foreach ($names as $name) {
                if (null !== $this->name && $name !== $this->name) {
                    continue;
                }

                $label = $labels->text($icons->labelOf($name), $icons->packageOf($name));

                if ('' === $search || str_contains(mb_strtolower($label), $search) || str_contains($name, $search)) {
                    $list[] = [$label, $name];
                }
            }

            usort($list, static fn(array $a, array $b): int => [mb_strtolower($a[0]), $a[1]] <=> [mb_strtolower($b[0]), $b[1]]);
            array_push($found, ...$list);
        }

        $page = \array_slice($found, ($this->page - 1) * $this->perPage, $this->perPage);
        $items = array_map(static fn(array $item): IconSummary => new IconSummary($item[1], $item[0], (string) $icons->svg($item[1], true), (string) $icons->setOf($item[1])), $page);

        return new IconList($items, \count($found), $this->page, $this->perPage, $sets);
    }
}
