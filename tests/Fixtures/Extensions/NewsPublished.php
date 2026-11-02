<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Fixtures\Extensions;

final class NewsPublished
{
    /** @var list<string> */
    public array $seenBy = [];

    public function __construct(public readonly string $title) {}
}
