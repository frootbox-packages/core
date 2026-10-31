<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * A page with its draft and its published state, the result of page.show
 * and of the actions that change a page.
 */
final readonly class PageDetails
{
    public function __construct(
        public string $id,
        public PageStatus $status,
        public ?int $version,
        public ?PageData $draft,
        public ?PageData $published,
    ) {}
}
