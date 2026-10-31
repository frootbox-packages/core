<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * What an action on the blocks of a page did: the block it is about
 * (a new one after adding or copying) and the entry in the action log,
 * for action.undo (decision 0123).
 */
final readonly class BlockChanged
{
    public function __construct(
        public string $id,
        public string $language,
        /** The block, null for a list of the layout or a removed block */
        public ?string $block,
        /** The entry in the action log, null inside another action */
        public ?int $entry,
    ) {}
}
