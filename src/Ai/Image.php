<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai;

/**
 * An image the model looks at, e.g. to write its alternative text.
 */
final readonly class Image implements Part
{
    public function __construct(
        /** E.g. "image/webp" */
        public string $mimeType,
        /** The file itself */
        public string $bytes,
    ) {}
}
