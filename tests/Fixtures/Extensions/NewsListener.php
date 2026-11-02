<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Extensions;

final class NewsListener
{
    public function __invoke(NewsPublished $event): void
    {
        $event->seenBy[] = 'listener';
    }
}
