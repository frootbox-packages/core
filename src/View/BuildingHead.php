<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched before the <head> of a page is written (decision 0104):
 *
 *     $registry->listen(BuildingHead::class, AddFonts::class);
 */
final readonly class BuildingHead
{
    public function __construct(
        public Head $head,
        public ServerRequestInterface $request,
        public ?string $pageId = null,
    ) {}
}
