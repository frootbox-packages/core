<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

/**
 * Where a published page lives in one language: its path without prefix
 * and the language its content comes from.
 */
final readonly class PagePath
{
    public function __construct(
        public string $pageId,
        public string $language,
        public string $path,
        public string $contentLanguage,
    ) {}

    public function isFallback(): bool
    {
        return $this->language !== $this->contentLanguage;
    }
}
