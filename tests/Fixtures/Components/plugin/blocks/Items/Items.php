<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Plugin\Blocks;

use Frootbox\Core\Component\BlockContext;
use Frootbox\Core\Component\BlockController;

final readonly class Items implements BlockController
{
    public function data(BlockContext $context): array
    {
        return ['subpage' => $context->subpage];
    }
}
