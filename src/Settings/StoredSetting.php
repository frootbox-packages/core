<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Settings;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * One changed setting of an extension: a value as JSON, or a secret
 * encrypted with APP_SECRET (decision 0221).
 */
final readonly class StoredSetting extends Entity
{
    public function __construct(
        public int $id,
        public string $package,
        public string $name,
        public ?string $value,
        public ?string $secret,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
