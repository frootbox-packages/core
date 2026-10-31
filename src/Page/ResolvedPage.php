<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Page;

/**
 * A published page found for a path, in one language.
 */
final readonly class ResolvedPage
{
    public function __construct(
        public string $id,
        public PageData $data,
        public string $language,
        public TranslationData $translation,
        public string $path,
    ) {}

    public function isStart(): bool
    {
        return null === $this->data->parentId;
    }
}
