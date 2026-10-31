<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Revision;

use DateTimeImmutable;
use Frootbox\Database\Entity;

/**
 * A snapshot of an entity: its draft, its published state or an older
 * version. The type is named like actions, e.g. "page" or
 * "acme/news/article".
 */
final readonly class Revision extends Entity
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $entityId,
        public RevisionStatus $status,
        public ?int $version,
        public array $data,
        public ?DateTimeImmutable $publishedAt,
        public ?DateTimeImmutable $publishAt,
        public ?DateTimeImmutable $unpublishAt,
        public ?int $authorId,
        public ?string $channel,
        public ?int $actionEntryId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {}
}
