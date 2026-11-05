<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Hub;

/**
 * An open request to connect (decision 0284): the browser goes to url,
 * where someone signs in and confirms it.
 */
final readonly class HubRequest
{
    public function __construct(
        public string $id,
        public string $url,
        public string $expiresAt,
    ) {}
}
