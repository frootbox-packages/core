<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

/**
 * One thing to find in one language (decision 0214). Who sees it
 * follows from the permission of a query and, with roles in branches,
 * from a scope such as the page.
 */
final readonly class SearchEntry
{
    public function __construct(
        public string $item,
        public string $title,
        public string $text,
        /** The query whose permission is needed to see it, e.g. "page.show" */
        public string $permission,
        /** Empty for things without a language, such as users */
        public string $language = '',
        /** The kind and id of the target for roles within branches, e.g. "page" */
        public ?string $scopeKind = null,
        public ?string $scopeId = null,
        /** The title is an English label of the CMS, translated for the reader */
        public bool $label = false,
    ) {}
}
