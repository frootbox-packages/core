<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;

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
        /** When the draft is published by itself */
        public ?DateTimeImmutable $publishAt = null,
        /** The lock of the published page, see page.lock */
        public Reach $locked = Reach::None,
        /** The page's own password, see page.protect (decision 0358) */
        public Reach $protected = Reach::None,
    ) {}
}
