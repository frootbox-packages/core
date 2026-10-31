<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * A page as editors see it: the draft if there is one, otherwise the
 * published state.
 */
final readonly class PageNode
{
    public function __construct(
        public string $id,
        public PageData $data,
        public PageStatus $status,
    ) {}
}
