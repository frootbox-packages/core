<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * The password of a page and maybe its subpages (decision 0358). It
 * applies right away and is not part of the draft.
 */
final readonly class PageProtection extends Entity
{
    public function __construct(
        public int $id,
        public string $pageId,
        public Reach $reach,
        public string $passwordHash,
        public string $generation,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
