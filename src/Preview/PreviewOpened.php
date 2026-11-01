<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
