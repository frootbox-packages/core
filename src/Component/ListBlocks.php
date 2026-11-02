<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\Scoped;
use Frootbox\Core\I18n\Labels;

/**
 * The blocks there are, for adding one to an area and for its settings
 * (decision 0123).
 *
 * @implements Query<list<BlockSummary>>
 */
#[AsQuery('block.list', 'Lists the blocks with title, description, variants with their options, fields and areas', permission: 'page.tree')]
final readonly class ListBlocks implements Query, Scoped
{
    /**
     * A role within a branch is enough to arrange blocks there.
     *
     * @return list<Scope>
     */
    public function scopes(): array
    {
        return [];
    }

    /**
     * @return list<BlockSummary>
     */
    public function handle(Components $components, Labels $labels): array
    {
        $blocks = array_map(static fn(Component $block): BlockSummary => BlockSummary::of($labels->component($block), offered: $components->offers(ComponentKind::Block, $block->name)), array_values($components->all(ComponentKind::Block)));
        usort($blocks, static fn(BlockSummary $a, BlockSummary $b): int => [$a->title, $a->name] <=> [$b->title, $b->name]);

        return $blocks;
    }
}
