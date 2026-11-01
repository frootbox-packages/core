<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Preview;

use DateTimeImmutable;

/**
 * A request attribute: the visitor has a valid preview link and sees the
 * website in the draft, read only (decision 0152).
 */
final readonly class PreviewAccess
{
    /** The cookie that keeps the link for the browser. */
    public const string COOKIE = 'frootbox_preview';

    public function __construct(
        public string $linkId,
        public DateTimeImmutable $expiresAt,
    ) {}
}
