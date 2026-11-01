<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Component\FieldSummary;

/**
 * The fields of the website with their values (decision 0128).
 */
final readonly class SiteDetails
{
    /**
     * @param list<FieldSummary>                  $fields
     * @param array<string, array<string, mixed>> $values By language, those of the draft if there is one; a language without its own value shows the one of the first language
     */
    public function __construct(
        /** The active theme, null without one */
        public ?string $theme,
        /** Whether there are changes not yet published */
        public bool $draft,
        /** The published version, null if nothing was published yet */
        public ?int $version,
        public array $fields,
        public array $values,
    ) {}
}
