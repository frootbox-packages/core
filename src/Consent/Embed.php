<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * Content of a well-known provider to embed (decision 0225).
 */
final readonly class Embed
{
    public function __construct(
        /** The name of the service, e.g. "youtube" */
        public string $service,
        /** The address of the iframe */
        public string $src,
        /** The content at the provider, for visitors without JavaScript */
        public string $link,
    ) {}
}
