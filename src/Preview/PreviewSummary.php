<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;

/**
 * A preview link as preview.list and preview.create answer it.
 */
final readonly class PreviewSummary
{
    public function __construct(
        public string $id,
        public ?string $note,
        public PreviewStatus $status,
        /** Where the link leads, with its token, e.g. "/?fb-preview=…"; null if APP_SECRET changed since */
        public ?string $path,
        /** The same as a full address, null without "app.url" in the configuration */
        public ?string $url,
        /** The page the link opens, null for the start page */
        public ?string $pageId,
        /** Whether the link opens the design page /_design (decision 0210) */
        public bool $design,
        /** The name of the user who created it */
        public ?string $createdBy,
        public ?DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt,
        public ?DateTimeImmutable $firstOpenedAt,
        public ?DateTimeImmutable $lastOpenedAt,
        /** How often the link was opened */
        public int $opens,
    ) {}
}
