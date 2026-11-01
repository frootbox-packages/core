<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;

/**
 * The index of where media are used comes from the content and can be
 * built anew at any time (decision 0146), e.g. after a theme changed its
 * fields or content came into the database another way.
 *
 * @implements Action<int>
 */
#[AsAction('system.media-usages', 'Builds the index of where media are used anew from pages and the fields of the website and returns how many entries changed')]
final readonly class RebuildMediaUsages implements Action
{
    public function handle(UsageIndex $index): int
    {
        return $index->rebuild();
    }
}
