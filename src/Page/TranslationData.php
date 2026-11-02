<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * Title, slug and content of a page in one language.
 */
final readonly class TranslationData
{
    /**
     * @param array<string, mixed> $content Options, fields and areas of the layout
     */
    public function __construct(
        public string $title,
        public string $slug,
        public array $content = [],
    ) {}
}
