<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

/**
 * A page in the image credits that shows a medium.
 */
final readonly class CreditPage
{
    public function __construct(
        public string $id,
        public string $title,
        public string $url,
    ) {}
}
