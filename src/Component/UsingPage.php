<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

/**
 * A page that uses a block or layout (decision 0276).
 */
final readonly class UsingPage
{
    /**
     * @param string $title in the default language, else the first one it has
     */
    public function __construct(
        public string $id,
        public string $title,
    ) {}
}
