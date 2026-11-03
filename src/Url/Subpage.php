<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Url;

/**
 * An object of a plugin shown on its base page (decision 0219), e.g. a
 * news article at /news/neue-sorten, or the page of a category there
 * (decision 0243), e.g. /news/presse.
 */
final readonly class Subpage
{
    public function __construct(
        public Target $target,
        /** The path below the base page, e.g. "neue-sorten" */
        public string $path,
        public string $title,
        /** See DescribedSubpages */
        public string $description = '',
        /** @var array{media: string, alt?: string}|null */
        public ?array $image = null,
        /** @var list<array{target: Target, title: string}> The pages between the base page and this one, e.g. the categories above */
        public array $parents = [],
    ) {}
}
