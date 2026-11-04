<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Page\PageData;
use Frootbox\Core\Page\PageReader;

/**
 * Which pages use which blocks and layouts, in the draft or published,
 * and whether their extension is still there (decision 0276). Before an
 * extension is removed it shows what it leaves on pages, afterwards
 * what is orphaned.
 *
 * @implements Query<list<ComponentUsage>>
 */
#[AsQuery('system.component-usages', 'Lists the blocks and layouts used on pages, in drafts or published, with the pages and whether their extension is installed')]
final readonly class ListComponentUsages implements Query
{
    public function __construct(
        #[Description('Only the blocks and layouts of this extension, e.g. "frootbox/news"')]
        public ?string $package = null,
        #[Description('Only those whose extension is not installed')]
        public bool $missing = false,
    ) {}

    /**
     * @return list<ComponentUsage>
     */
    public function handle(PageReader $reader, Components $components, Languages $languages): array
    {
        $default = $languages->default()->code;
        /** @var array<string, array{ComponentKind, array<string, string>}> $found name => kind, pages by id */
        $found = [];

        foreach ([$reader->nodes(), $reader->nodesAfterPublishing([])] as $nodes) {
            foreach ($nodes as $id => $node) {
                $title = self::title($node->data, $default);
                $found[$node->data->layout][0] = ComponentKind::Layout;
                $found[$node->data->layout][1][$id] = $title;

                foreach ($node->data->translations as $translation) {
                    foreach (self::blocks($translation->content) as $name) {
                        $found[$name][0] ??= ComponentKind::Block;
                        $found[$name][1][$id] = $title;
                    }
                }
            }
        }

        $usages = [];

        foreach ($found as $name => [$kind, $pages]) {
            $package = ComponentName::package($name);
            $installed = null !== $components->get($kind, $name);

            if ((null !== $this->package && $package !== $this->package) || ($this->missing && $installed)) {
                continue;
            }

            asort($pages);
            $usages[] = new ComponentUsage($name, $kind, $package, $installed, array_map(
                static fn(string $id, string $title): UsingPage => new UsingPage($id, $title),
                array_map(strval(...), array_keys($pages)),
                array_values($pages),
            ));
        }

        usort($usages, static fn(ComponentUsage $a, ComponentUsage $b): int => [$a->kind->value, $a->name] <=> [$b->kind->value, $b->name]);

        return $usages;
    }

    private static function title(PageData $data, string $default): string
    {
        $translation = $data->translations[$default] ?? array_values($data->translations)[0] ?? null;

        return $translation->title ?? '';
    }

    /**
     * Names of the blocks in content, at any depth, the view of a plugin too.
     *
     * @param array<mixed> $content
     *
     * @return list<string>
     */
    private static function blocks(array $content): array
    {
        $names = [];
        $pending = [$content];

        while ([] !== $pending) {
            $block = array_pop($pending);

            if (!\is_array($block)) {
                continue;
            }

            if (\is_string($block['block'] ?? null)) {
                $names[] = $block['block'];
            }

            if (\is_array($block['plugin'] ?? null)) {
                $pending[] = $block['plugin'];
            }

            foreach (\is_array($block['areas'] ?? null) ? $block['areas'] : [] as $list) {
                foreach (\is_array($list) ? $list : [] as $child) {
                    $pending[] = $child;
                }
            }
        }

        return array_values(array_unique($names));
    }
}
