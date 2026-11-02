<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Extensions;

use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;

/**
 * Test extension that uses every extension point.
 */
final class NewsExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->get('/news', NewsHandler::class, 'news');
        $registry->listen(NewsPublished::class, NewsListener::class);
        $registry->command(ImportNewsCommand::class);
    }
}
