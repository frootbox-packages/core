<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\View;

use Frootbox\Core\Url\Target;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dispatched before the <head> of a page is written (decisions 0104 and
 * 0105). The target says what is delivered, e.g. page:01J…:
 *
 *     $registry->listen(BuildingHead::class, AddFonts::class);
 */
final readonly class BuildingHead
{
    public function __construct(
        public Head $head,
        public ServerRequestInterface $request,
        public ?Target $target = null,
    ) {}
}
