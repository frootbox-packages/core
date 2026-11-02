<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;

/**
 * What preview.open answers: the link is valid until then.
 */
final readonly class PreviewOpened
{
    public function __construct(
        public string $id,
        public DateTimeImmutable $expiresAt,
    ) {}
}
