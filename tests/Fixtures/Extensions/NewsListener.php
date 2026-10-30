<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
