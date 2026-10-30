<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Extensions;

final class NewsPublished
{
    /** @var list<string> */
    public array $seenBy = [];

    public function __construct(public readonly string $title) {}
}
